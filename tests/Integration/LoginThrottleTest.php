<?php

declare(strict_types=1);

namespace Nimbus\Tests\Integration;

use Nimbus\Auth\LoginThrottle;

final class LoginThrottleTest extends IntegrationTestCase
{
    private LoginThrottle $throttle;

    protected function setUp(): void
    {
        parent::setUp();
        $this->throttle = new LoginThrottle($this->db);
    }

    private function insertRow(string $key, string $lastAttempt, ?string $lockedUntil): void
    {
        $this->db->execute(
            'INSERT INTO nb_login_throttle (id, attempts, last_attempt, locked_until) VALUES (:k, :a, :t, :l)',
            ['k' => $key, 'a' => 5, 't' => $lastAttempt, 'l' => $lockedUntil],
        );
    }

    // ------------------------------------------------------------- FU-10: prune

    public function test_prune_removes_a_stale_decayed_row(): void
    {
        $this->insertRow('stale', date('Y-m-d H:i:s', time() - 2 * 24 * 3600), null);
        self::assertSame(1, $this->throttle->prune(24 * 3600));
    }

    public function test_prune_preserves_a_row_under_an_active_lockout(): void
    {
        // last_attempt is ancient (older than the cutoff) but the lockout is
        // still in the future — pruning it would reset the counter and hand the
        // client a fresh window (an AUTH-2 lockout bypass). It must survive.
        $this->insertRow('locked', date('Y-m-d H:i:s', time() - 2 * 24 * 3600), date('Y-m-d H:i:s', time() + 1800));

        self::assertSame(0, $this->throttle->prune(24 * 3600), 'an actively-locking row is never pruned');
        self::assertTrue($this->throttle->tooManyAttempts('locked'), 'the lockout survives the prune');
    }

    public function test_prune_is_a_safe_noop_on_an_empty_table(): void
    {
        self::assertSame(0, $this->throttle->prune(24 * 3600));
    }

    public function test_locks_after_threshold(): void
    {
        $key = '10.0.0.1';
        self::assertFalse($this->throttle->tooManyAttempts($key));

        for ($i = 0; $i < 5; $i++) {
            $this->throttle->recordFailure($key);
        }

        self::assertTrue($this->throttle->tooManyAttempts($key));
        self::assertGreaterThan(0, $this->throttle->lockedFor($key));
    }

    public function test_below_threshold_is_not_locked(): void
    {
        $key = '10.0.0.2';
        for ($i = 0; $i < 4; $i++) {
            $this->throttle->recordFailure($key);
        }
        self::assertFalse($this->throttle->tooManyAttempts($key));
    }

    public function test_clear_resets_the_key(): void
    {
        $key = '10.0.0.3';
        for ($i = 0; $i < 6; $i++) {
            $this->throttle->recordFailure($key);
        }
        self::assertTrue($this->throttle->tooManyAttempts($key));

        $this->throttle->clear($key);
        self::assertFalse($this->throttle->tooManyAttempts($key));
    }

    public function test_keys_are_independent(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $this->throttle->recordFailure('10.0.0.4');
        }
        self::assertTrue($this->throttle->tooManyAttempts('10.0.0.4'));
        self::assertFalse($this->throttle->tooManyAttempts('10.0.0.5'));
    }
}

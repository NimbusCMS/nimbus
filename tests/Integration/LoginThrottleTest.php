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

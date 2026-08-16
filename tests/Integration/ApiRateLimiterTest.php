<?php

declare(strict_types=1);

namespace Nimbus\Tests\Integration;

use Nimbus\Http\ApiRateLimiter;

/**
 * The fixed-window counter. The clock is injected so the window boundary is
 * crossed deterministically, without sleeping.
 */
final class ApiRateLimiterTest extends IntegrationTestCase
{
    public function test_it_allows_up_to_the_limit_then_refuses(): void
    {
        $limiter = new ApiRateLimiter($this->db, static fn (): int => 1_000);

        for ($i = 1; $i <= 3; $i++) {
            self::assertFalse($limiter->hit('k', 3, 60)->exceeded, "hit {$i} is within the limit");
        }
        $over = $limiter->hit('k', 3, 60);
        self::assertTrue($over->exceeded, 'the 4th hit is over the limit');
        self::assertSame(0, $over->remaining);
        self::assertGreaterThan(0, $over->retryAfter);
    }

    public function test_remaining_counts_down(): void
    {
        $limiter = new ApiRateLimiter($this->db, static fn (): int => 1_000);

        self::assertSame(4, $limiter->hit('k', 5, 60)->remaining);
        self::assertSame(3, $limiter->hit('k', 5, 60)->remaining);
    }

    public function test_a_new_window_starts_fresh(): void
    {
        $now     = 1_000;
        $limiter = new ApiRateLimiter($this->db, static function () use (&$now): int {
            return $now;
        });

        $limiter->hit('k', 1, 60);
        self::assertTrue($limiter->hit('k', 1, 60)->exceeded, 'the second hit fills the window');

        $now += 60; // cross into the next window
        self::assertFalse($limiter->hit('k', 1, 60)->exceeded, 'the next window is a clean slate');
    }

    public function test_keys_are_independent(): void
    {
        $limiter = new ApiRateLimiter($this->db, static fn (): int => 1_000);

        $limiter->hit('a', 1, 60);
        self::assertFalse($limiter->hit('b', 1, 60)->exceeded, 'a different key has its own bucket');
    }

    public function test_prune_removes_stale_rows_but_keeps_fresh_ones(): void
    {
        $now     = 1_000_000;
        $limiter = new ApiRateLimiter($this->db, static function () use (&$now): int {
            return $now;
        });

        $limiter->hit('old', 10, 60);
        $now += 100_000; // advance well past a day
        $limiter->hit('fresh', 10, 60);

        $removed = $limiter->prune(24 * 3600);

        self::assertSame(1, $removed);
        self::assertNull($this->db->selectOne('SELECT id FROM nb_api_rate WHERE id = :k', ['k' => 'old']));
        self::assertNotNull($this->db->selectOne('SELECT id FROM nb_api_rate WHERE id = :k', ['k' => 'fresh']));
    }
}

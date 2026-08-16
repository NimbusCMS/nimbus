<?php

declare(strict_types=1);

namespace Nimbus\Http;

use Nimbus\Database\Connection;

/**
 * A fixed-window request counter, DB-backed.
 *
 * Each key gets one row holding the current window's start (a unix bucket) and
 * the hit count within it. A hit either increments the count in the current
 * window or, when the clock has crossed into a new window, resets it to 1 — both
 * in a single atomic upsert.
 *
 * Fixed window is deliberately simple: it can admit up to 2× the limit across a
 * window boundary, which is an acceptable trade for a read API at this scale. The
 * store is the database to honour the zero-new-runtime-deps rule; a busier
 * deployment swaps this for a cache adapter (see the roadmap) without touching
 * the middleware. The clock is injectable so tests cross a window deterministically.
 *
 * Operational note: rows are keyed by IP/token and are not self-expiring, so a
 * long-lived install accumulates stale rows (one per distinct key seen). Prune
 * them periodically (`DELETE FROM nb_api_rate WHERE updated_at < …`); a cache
 * adapter would expire them for free. Tracked on the roadmap.
 */
final class ApiRateLimiter
{
    /** @var callable(): int */
    private $clock;

    /** @param ?callable(): int $clock unix-seconds source; defaults to time() */
    public function __construct(private Connection $db, ?callable $clock = null)
    {
        $this->clock = $clock ?? static fn (): int => time();
    }

    public function hit(string $key, int $limit, int $window): RateLimitResult
    {
        $now    = ($this->clock)();
        $bucket = $now - ($now % $window);

        // Atomic: within the same window, count up; on a new window, reset to 1.
        // The existing row's columns are qualified (nb_api_rate.*) since the row
        // alias `new` shares their names; the IF is evaluated before window_start
        // is reassigned, so it compares the stored window to the incoming one.
        $this->db->execute(
            'INSERT INTO nb_api_rate (id, window_start, hits, updated_at) VALUES (:k, :w, 1, :t) AS new
             ON DUPLICATE KEY UPDATE
                hits         = IF(nb_api_rate.window_start = new.window_start, nb_api_rate.hits + 1, 1),
                window_start = new.window_start,
                updated_at   = new.updated_at',
            ['k' => $key, 'w' => $bucket, 't' => date('Y-m-d H:i:s', $now)],
        );

        $hits  = (int) ($this->db->selectOne('SELECT hits FROM nb_api_rate WHERE id = :k', ['k' => $key])['hits'] ?? 1);
        $reset = $bucket + $window;

        return new RateLimitResult(
            exceeded: $hits > $limit,
            limit: $limit,
            remaining: max(0, $limit - $hits),
            reset: $reset,
            retryAfter: max(1, $reset - $now),
        );
    }

    /**
     * Delete counter rows last touched more than $olderThanSeconds ago — a window
     * that has long since rolled over is dead weight. Returns the rows removed.
     * Run from `nimbus prune`.
     */
    public function prune(int $olderThanSeconds): int
    {
        $cutoff = date('Y-m-d H:i:s', ($this->clock)() - $olderThanSeconds);

        return $this->db->execute('DELETE FROM nb_api_rate WHERE updated_at < :cutoff', ['cutoff' => $cutoff]);
    }
}

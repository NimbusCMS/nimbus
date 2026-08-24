<?php

declare(strict_types=1);

namespace Nimbus\Auth;

use Nimbus\Database\Connection;

/**
 * Progressive login throttling keyed by client IP. After a threshold of failures
 * within a decay window, the key is locked for a doubling delay (capped). A
 * successful login clears the record.
 */
final class LoginThrottle
{
    private const THRESHOLD = 5;    // failures before lockout kicks in
    private const DECAY     = 900;  // seconds; older failures don't count
    private const MAX_LOCK  = 3600; // cap the lockout at 1 hour

    public function __construct(private Connection $db)
    {
    }

    public function lockedFor(string $key): int
    {
        $row = $this->db->selectOne('SELECT locked_until FROM nb_login_throttle WHERE id = :k', ['k' => $key]);
        if ($row === null || $row['locked_until'] === null) {
            return 0;
        }
        return max(0, strtotime((string) $row['locked_until']) - time());
    }

    public function tooManyAttempts(string $key): bool
    {
        return $this->lockedFor($key) > 0;
    }

    public function recordFailure(string $key): void
    {
        $now = time();
        $row = $this->db->selectOne('SELECT attempts, last_attempt FROM nb_login_throttle WHERE id = :k', ['k' => $key]);

        $attempts = 1;
        if ($row !== null) {
            $withinWindow = strtotime((string) $row['last_attempt']) >= $now - self::DECAY;
            $attempts = $withinWindow ? (int) $row['attempts'] + 1 : 1;
        }

        $lockedUntil = null;
        if ($attempts >= self::THRESHOLD) {
            $delay = min(self::MAX_LOCK, 60 * (2 ** ($attempts - self::THRESHOLD)));
            $lockedUntil = date('Y-m-d H:i:s', $now + $delay);
        }

        // Row alias (MySQL 8.0.19+) so each placeholder is bound once — native
        // prepared statements forbid reusing a named placeholder.
        $this->db->execute(
            'INSERT INTO nb_login_throttle (id, attempts, last_attempt, locked_until) VALUES (:k, :a, :t, :l) AS new
             ON DUPLICATE KEY UPDATE attempts = new.attempts, last_attempt = new.last_attempt, locked_until = new.locked_until',
            ['k' => $key, 'a' => $attempts, 't' => date('Y-m-d H:i:s', $now), 'l' => $lockedUntil],
        );
    }

    public function clear(string $key): void
    {
        $this->db->execute('DELETE FROM nb_login_throttle WHERE id = :k', ['k' => $key]);
    }

    /**
     * Remove decayed throttle rows so the table doesn't accumulate one row per
     * distinct IP/email key forever (FU-10). Run from `nimbus prune`.
     *
     * A row is prunable only when its last attempt is older than
     * `$olderThanSeconds` **and** it is not under an active lockout: deleting a
     * currently-locking row would reset the counter and let a locked-out client
     * start a fresh window (an AUTH-2 lockout bypass), so the `locked_until`
     * guard is load-bearing regardless of the age passed. Pass an age ≥
     * {@see MAX_LOCK} so a row still inside its counting/lock window is never
     * pruned mid-accumulation. Returns the number of rows removed.
     */
    public function prune(int $olderThanSeconds): int
    {
        $now = time();
        return $this->db->execute(
            'DELETE FROM nb_login_throttle
             WHERE last_attempt < :cutoff AND (locked_until IS NULL OR locked_until < :now)',
            ['cutoff' => date('Y-m-d H:i:s', $now - $olderThanSeconds), 'now' => date('Y-m-d H:i:s', $now)],
        );
    }
}

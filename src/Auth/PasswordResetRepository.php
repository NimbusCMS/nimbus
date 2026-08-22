<?php

declare(strict_types=1);

namespace Nimbus\Auth;

use Nimbus\Database\Connection;

/**
 * Persistence for one-time credential tokens — password **reset** and user
 * **invitation** — in `nb_password_resets`. Only the SHA-256 hash of the token
 * is stored (mirroring {@see \Nimbus\Api\ApiTokenRepository}); the plaintext
 * lives only in the emailed link, and lookups are **by hash** (no raw-token
 * timing, nothing brute-forceable at rest).
 *
 * Every query is scoped by **purpose**, so a reset token can never satisfy an
 * invite check (or vice versa) and issuing one purpose never invalidates the
 * other's outstanding tokens.
 */
final class PasswordResetRepository
{
    /** Reset links live an hour; invites longer (async human onboarding) — the caller passes the TTL. */
    public const TTL_SECONDS = 3600;
    public const PURPOSE_RESET = 'reset';
    public const PURPOSE_INVITE = 'invite';

    public function __construct(private Connection $db)
    {
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /** Store a token's hash for a user under a purpose, expiring in $ttlSeconds. */
    public function create(int $userId, string $token, string $purpose = self::PURPOSE_RESET, int $ttlSeconds = self::TTL_SECONDS): void
    {
        $now = time();
        $this->db->execute(
            'INSERT INTO nb_password_resets (user_id, purpose, token_hash, expires_at, created_at)
             VALUES (:u, :p, :h, :e, :c)',
            [
                'u' => $userId,
                'p' => $purpose,
                'h' => self::hash($token),
                'e' => date('Y-m-d H:i:s', $now + $ttlSeconds),
                'c' => date('Y-m-d H:i:s', $now),
            ],
        );
    }

    /** The user id for a currently-valid token of this purpose (unused, unexpired), or null. Does not consume. */
    public function userIdForValidToken(string $token, string $purpose = self::PURPOSE_RESET): ?int
    {
        $row = $this->db->selectOne(
            'SELECT user_id FROM nb_password_resets
             WHERE token_hash = :h AND purpose = :p AND used_at IS NULL AND expires_at > NOW()',
            ['h' => self::hash($token), 'p' => $purpose],
        );
        return $row === null ? null : (int) $row['user_id'];
    }

    /**
     * Atomically consume a valid token of this purpose, returning its user id —
     * or null if already used/expired/unknown/wrong-purpose. The
     * `used_at IS NULL` guard is the single-winner lock: exactly one concurrent
     * request sees an affected row, so a token is never spent twice.
     */
    public function consume(string $token, string $purpose = self::PURPOSE_RESET): ?int
    {
        $hash   = self::hash($token);
        $userId = $this->userIdForValidToken($token, $purpose);
        if ($userId === null) {
            return null;
        }

        $affected = $this->db->execute(
            'UPDATE nb_password_resets SET used_at = NOW()
             WHERE token_hash = :h AND purpose = :p AND used_at IS NULL AND expires_at > NOW()',
            ['h' => $hash, 'p' => $purpose],
        );

        return $affected === 1 ? $userId : null;
    }

    /** Invalidate a user's outstanding (unused) tokens of one purpose only — leaving the other purpose untouched. */
    public function invalidateForUser(int $userId, string $purpose = self::PURPOSE_RESET): void
    {
        $this->db->execute(
            'UPDATE nb_password_resets SET used_at = NOW() WHERE user_id = :u AND purpose = :p AND used_at IS NULL',
            ['u' => $userId, 'p' => $purpose],
        );
    }
}

<?php

declare(strict_types=1);

namespace Nimbus\Auth;

use Nimbus\Database\Connection;

/**
 * Persistence for one-time password-reset tokens. Only the SHA-256 hash of the
 * token is ever stored (mirroring {@see \Nimbus\Api\ApiTokenRepository}); the
 * plaintext lives only in the emailed link. Lookups are **by hash**, so there is
 * no timing signal on the raw token and nothing brute-forceable at rest.
 */
final class PasswordResetRepository
{
    /** Reset links live an hour — long enough to click, short enough to bound leakage. */
    public const TTL_SECONDS = 3600;

    public function __construct(private Connection $db)
    {
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /** Store a token's hash for a user, expiring in {@see TTL_SECONDS}. */
    public function create(int $userId, string $token): void
    {
        $now = time();
        $this->db->execute(
            'INSERT INTO nb_password_resets (user_id, token_hash, expires_at, created_at)
             VALUES (:u, :h, :e, :c)',
            [
                'u' => $userId,
                'h' => self::hash($token),
                'e' => date('Y-m-d H:i:s', $now + self::TTL_SECONDS),
                'c' => date('Y-m-d H:i:s', $now),
            ],
        );
    }

    /**
     * The user id for a currently-valid token (unused, unexpired), or null.
     * Read-only — does not consume; used to render the reset form.
     */
    public function userIdForValidToken(string $token): ?int
    {
        $row = $this->db->selectOne(
            'SELECT user_id FROM nb_password_resets
             WHERE token_hash = :h AND used_at IS NULL AND expires_at > NOW()',
            ['h' => self::hash($token)],
        );
        return $row === null ? null : (int) $row['user_id'];
    }

    /**
     * Atomically consume a valid token, returning its user id — or null if it was
     * already used/expired/unknown. The `used_at IS NULL` guard in the UPDATE is
     * the single-winner lock: two concurrent requests race on the row and exactly
     * one sees an affected row, so a token can never be spent twice.
     */
    public function consume(string $token): ?int
    {
        $hash = self::hash($token);
        $userId = $this->userIdForValidToken($token);
        if ($userId === null) {
            return null;
        }

        $affected = $this->db->execute(
            'UPDATE nb_password_resets SET used_at = NOW()
             WHERE token_hash = :h AND used_at IS NULL AND expires_at > NOW()',
            ['h' => $hash],
        );

        return $affected === 1 ? $userId : null;
    }

    /** Invalidate all of a user's outstanding (unused) tokens — after a reset, or before issuing a new one. */
    public function invalidateForUser(int $userId): void
    {
        $this->db->execute(
            'UPDATE nb_password_resets SET used_at = NOW() WHERE user_id = :u AND used_at IS NULL',
            ['u' => $userId],
        );
    }
}

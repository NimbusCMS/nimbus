<?php

declare(strict_types=1);

namespace Nimbus\Api;

use Nimbus\Database\Connection;

/**
 * Stores and resolves API tokens.
 *
 * Tokens are high-entropy random strings, so they are stored as a plain
 * SHA-256 hash rather than a slow password hash: there is nothing to brute-force
 * in a 160-bit random value, and a fast indexed equality lookup is what
 * authenticating every API request needs. The plaintext is returned exactly
 * once, at creation, and never recoverable afterwards.
 *
 * The lifecycle (ADR 0006) lives here as one gate: `findByPlaintext` resolves a
 * token only if it is *active*, so a revoked, expired or paused token is
 * indistinguishable from an unknown one to the caller and its state never leaks.
 */
final class ApiTokenRepository
{
    /** Human-visible prefix so a leaked token is recognisable as a Nimbus one. */
    private const PREFIX = 'nbt_';

    private const COLUMNS = 'id, name, abilities, last_used_at, used_count, last_used_ip, expires_at, revoked_at, paused_at';

    public function __construct(private Connection $db)
    {
    }

    /**
     * Mint a token. Returns the plaintext — the only time it exists — for the
     * caller to show once. Only the hash is persisted.
     *
     * @param string[] $abilities
     * @param ?string  $expiresAt an absolute 'Y-m-d H:i:s'; null never expires
     */
    public function create(string $name, array $abilities = [], ?string $expiresAt = null): string
    {
        $plain = self::PREFIX . bin2hex(random_bytes(20));

        $this->db->execute(
            'INSERT INTO nb_api_tokens (name, token_hash, abilities, expires_at, created_at)
             VALUES (:n, :h, :a, :e, :c)',
            [
                'n' => $name,
                'h' => self::hash($plain),
                'a' => $abilities === [] ? null : json_encode(array_values($abilities), JSON_THROW_ON_ERROR),
                'e' => $expiresAt,
                'c' => date('Y-m-d H:i:s'),
            ],
        );

        return $plain;
    }

    /** Resolve a presented plaintext token to its stored record — only if active. */
    public function findByPlaintext(string $plain): ?ApiToken
    {
        if (!str_starts_with($plain, self::PREFIX)) {
            return null;
        }
        $row = $this->db->selectOne(
            'SELECT ' . self::COLUMNS . ' FROM nb_api_tokens WHERE token_hash = :h',
            ['h' => self::hash($plain)],
        );
        if ($row === null) {
            return null;
        }

        $token = self::hydrate($row);

        // Revoked, expired or paused authenticates as nothing — the same "not
        // valid" an unknown token gets, so lifecycle state never leaks.
        return $token->isActive() ? $token : null;
    }

    /**
     * Every token, active or not, newest first — for management surfaces (the
     * CLI and the admin page). Never exposes the hash.
     *
     * @return ApiToken[]
     */
    public function all(): array
    {
        return array_map(
            self::hydrate(...),
            $this->db->select('SELECT ' . self::COLUMNS . ' FROM nb_api_tokens ORDER BY id DESC'),
        );
    }

    /** Record a use: stamp the time, bump the count, remember the caller's IP. */
    public function touch(int $id, ?string $ip = null): void
    {
        $this->db->execute(
            'UPDATE nb_api_tokens
                SET last_used_at = :t, used_count = used_count + 1, last_used_ip = :ip
              WHERE id = :id',
            ['t' => date('Y-m-d H:i:s'), 'ip' => $ip, 'id' => $id],
        );
    }

    /** Permanently revoke a token. Terminal and idempotent: the timestamp never moves. */
    public function revoke(int $id): void
    {
        $this->db->execute(
            'UPDATE nb_api_tokens SET revoked_at = :t WHERE id = :id AND revoked_at IS NULL',
            ['t' => date('Y-m-d H:i:s'), 'id' => $id],
        );
    }

    /** Temporarily disable a token. Only an un-revoked, not-already-paused token pauses. */
    public function pause(int $id): void
    {
        $this->db->execute(
            'UPDATE nb_api_tokens SET paused_at = :t WHERE id = :id AND revoked_at IS NULL AND paused_at IS NULL',
            ['t' => date('Y-m-d H:i:s'), 'id' => $id],
        );
    }

    /** Re-enable a paused token. Revocation wins — a revoked token cannot be resumed. */
    public function resume(int $id): void
    {
        $this->db->execute(
            'UPDATE nb_api_tokens SET paused_at = NULL WHERE id = :id AND revoked_at IS NULL',
            ['id' => $id],
        );
    }

    /** @param array<string,mixed> $row */
    private static function hydrate(array $row): ApiToken
    {
        $abilities = [];
        if (is_string($row['abilities'] ?? null) && $row['abilities'] !== '') {
            $decoded   = json_decode((string) $row['abilities'], true);
            $abilities = is_array($decoded) ? array_values(array_map('strval', $decoded)) : [];
        }

        return new ApiToken(
            (int) $row['id'],
            (string) $row['name'],
            $abilities,
            self::nullableString($row['last_used_at'] ?? null),
            (int) ($row['used_count'] ?? 0),
            self::nullableString($row['last_used_ip'] ?? null),
            self::nullableString($row['expires_at'] ?? null),
            self::nullableString($row['revoked_at'] ?? null),
            self::nullableString($row['paused_at'] ?? null),
        );
    }

    private static function nullableString(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }

    private static function hash(string $plain): string
    {
        return hash('sha256', $plain);
    }
}

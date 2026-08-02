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
 */
final class ApiTokenRepository
{
    /** Human-visible prefix so a leaked token is recognisable as a Nimbus one. */
    private const PREFIX = 'nbt_';

    public function __construct(private Connection $db)
    {
    }

    /**
     * Mint a token. Returns the plaintext — the only time it exists — for the
     * caller to show once. Only the hash is persisted.
     *
     * @param string[] $abilities
     */
    public function create(string $name, array $abilities = []): string
    {
        $plain = self::PREFIX . bin2hex(random_bytes(20));

        $this->db->execute(
            'INSERT INTO nb_api_tokens (name, token_hash, abilities, created_at) VALUES (:n, :h, :a, :c)',
            [
                'n' => $name,
                'h' => self::hash($plain),
                'a' => $abilities === [] ? null : json_encode(array_values($abilities), JSON_THROW_ON_ERROR),
                'c' => date('Y-m-d H:i:s'),
            ],
        );

        return $plain;
    }

    /** Resolve a presented plaintext token to its stored record, or null. */
    public function findByPlaintext(string $plain): ?ApiToken
    {
        if (!str_starts_with($plain, self::PREFIX)) {
            return null;
        }
        $row = $this->db->selectOne(
            'SELECT id, name, abilities, last_used_at FROM nb_api_tokens WHERE token_hash = :h',
            ['h' => self::hash($plain)],
        );
        if ($row === null) {
            return null;
        }

        $abilities = [];
        if (is_string($row['abilities'] ?? null) && $row['abilities'] !== '') {
            $decoded   = json_decode($row['abilities'], true);
            $abilities = is_array($decoded) ? array_values(array_map('strval', $decoded)) : [];
        }

        return new ApiToken((int) $row['id'], (string) $row['name'], $abilities, $row['last_used_at'] ?? null);
    }

    /** Record that a token was just used, for an at-a-glance "last seen". */
    public function touch(int $id): void
    {
        $this->db->execute('UPDATE nb_api_tokens SET last_used_at = :t WHERE id = :id', ['t' => date('Y-m-d H:i:s'), 'id' => $id]);
    }

    private static function hash(string $plain): string
    {
        return hash('sha256', $plain);
    }
}

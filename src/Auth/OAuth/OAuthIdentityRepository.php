<?php

declare(strict_types=1);

namespace Nimbus\Auth\OAuth;

use Nimbus\Database\Connection;
use PDOException;

/**
 * The link table between Nimbus users and external identities (ADR 0012). Lookups
 * and writes are always keyed by `(provider, provider_user_id)` — the immutable
 * subject — never by email.
 */
final class OAuthIdentityRepository
{
    public function __construct(private Connection $db)
    {
    }

    /** The Nimbus user linked to this provider subject, or null if the identity is unknown. */
    public function userIdFor(string $provider, string $subject): ?int
    {
        $row = $this->db->selectOne(
            'SELECT user_id FROM nb_oauth_identities WHERE provider = :p AND provider_user_id = :s',
            ['p' => $provider, 's' => $subject],
        );
        return $row === null ? null : (int) $row['user_id'];
    }

    /**
     * Link a provider subject to a user. Returns false when the identity is
     * already linked (UNIQUE conflict) — the caller reports a graceful "already
     * connected" rather than stealing or duplicating the link.
     */
    public function link(int $userId, string $provider, string $subject, string $email): bool
    {
        try {
            $this->db->execute(
                'INSERT INTO nb_oauth_identities (user_id, provider, provider_user_id, email, created_at)
                 VALUES (:u, :p, :s, :e, :t)',
                ['u' => $userId, 'p' => $provider, 's' => $subject, 'e' => $email !== '' ? $email : null, 't' => date('Y-m-d H:i:s')],
            );
            return true;
        } catch (PDOException $e) {
            if (Connection::isDuplicateKey($e)) {
                return false;
            }
            throw $e;
        }
    }

    /** Remove a user's link to a provider (used by "Disconnect" in settings). */
    public function unlink(int $userId, string $provider): void
    {
        $this->db->execute(
            'DELETE FROM nb_oauth_identities WHERE user_id = :u AND provider = :p',
            ['u' => $userId, 'p' => $provider],
        );
    }

    /**
     * The providers a user has linked, for the settings screen.
     *
     * @return array<string,array{email:?string,created_at:string}> keyed by provider
     */
    public function forUser(int $userId): array
    {
        $rows = $this->db->select(
            'SELECT provider, email, created_at FROM nb_oauth_identities WHERE user_id = :u ORDER BY provider',
            ['u' => $userId],
        );
        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row['provider']] = [
                'email'      => $row['email'] !== null ? (string) $row['email'] : null,
                'created_at' => (string) $row['created_at'],
            ];
        }
        return $out;
    }
}

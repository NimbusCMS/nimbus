<?php

declare(strict_types=1);

namespace Nimbus\Content;

use Nimbus\Database\Connection;

/**
 * Draft-preview tokens (ADR 0021): a short-lived, entry-scoped grant that lets the
 * holder view ONE unpublished entry — through the rendered site (`?preview=`) or
 * the headless preview endpoint — without authenticating.
 *
 * The security model mirrors {@see \Nimbus\Auth\PasswordResetRepository}: the token
 * is 256 bits of CSPRNG entropy; only its SHA-256 hash is stored, so a DB read
 * never yields a usable link and the lookup (hash of a random secret) has nothing
 * to brute-force. The token binds `collection_id` + `entry_id`, so {@see resolve}
 * can only ever name that one entry — a token for entry A cannot reveal entry B.
 * Issuing is authorised at mint time (the caller holds the collection's `:read`);
 * the token then carries the grant for its TTL. Expiry is enforced in the query.
 */
final class PreviewTokens
{
    /** 30 minutes — long enough to review, short enough to bound a leaked link. */
    public const TTL_SECONDS = 1800;

    public function __construct(private Connection $db)
    {
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Mint a preview token for one entry and return the plaintext (shown once, in
     * the shareable link). The caller must already have checked the issuer holds
     * the collection's capability.
     */
    public function issue(int $collectionId, int $entryId, ?int $createdBy, int $ttlSeconds = self::TTL_SECONDS): string
    {
        $token = bin2hex(random_bytes(32));
        $this->db->execute(
            'INSERT INTO nb_preview_tokens (collection_id, entry_id, token_hash, created_by, expires_at, created_at)
             VALUES (:c, :e, :h, :u, :exp, :now)',
            [
                'c'   => $collectionId,
                'e'   => $entryId,
                'h'   => self::hash($token),
                'u'   => $createdBy,
                'exp' => date('Y-m-d H:i:s', time() + max(60, $ttlSeconds)),
                'now' => date('Y-m-d H:i:s'),
            ],
        );
        return $token;
    }

    /**
     * Resolve a preview token to the single entry it grants, or null if it is
     * absent, malformed, or expired. Returns no other signal — callers fall
     * through to their normal not-found path on null, so an invalid token is
     * indistinguishable from no token at all.
     *
     * @return array{collection_id:int,entry_id:int}|null
     */
    public function resolve(string $token): ?array
    {
        if ($token === '') {
            return null;
        }
        $row = $this->db->selectOne(
            'SELECT collection_id, entry_id FROM nb_preview_tokens WHERE token_hash = :h AND expires_at > :now',
            ['h' => self::hash($token), 'now' => date('Y-m-d H:i:s')],
        );
        if ($row === null) {
            return null;
        }
        return ['collection_id' => (int) $row['collection_id'], 'entry_id' => (int) $row['entry_id']];
    }
}

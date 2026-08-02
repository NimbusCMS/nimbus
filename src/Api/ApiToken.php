<?php

declare(strict_types=1);

namespace Nimbus\Api;

/**
 * An API token as stored — never the secret itself.
 *
 * The plaintext token exists only for the instant it is minted; after that only
 * its SHA-256 hash is kept, so a leaked database row cannot be replayed against
 * the API. This object is what a lookup returns once a presented token has been
 * matched by hash.
 */
final readonly class ApiToken
{
    /** @param string[] $abilities reserved for scoping; every token can read today */
    public function __construct(
        public int $id,
        public string $name,
        public array $abilities,
        public ?string $lastUsedAt,
    ) {
    }
}

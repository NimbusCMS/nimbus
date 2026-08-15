<?php

declare(strict_types=1);

namespace Nimbus\Api;

/**
 * An API token as stored — never the secret itself.
 *
 * The plaintext token exists only for the instant it is minted; after that only
 * its SHA-256 hash is kept, so a leaked database row cannot be replayed against
 * the API. This object is what a lookup returns once a presented token has been
 * matched by hash, and it also carries the lifecycle state (ADR 0006) the
 * management surfaces display and the resolver gates on.
 *
 * The state is *derived* from timestamps rather than a status column, so there
 * is one source of truth: revocation is terminal, a pause is reversible, and
 * expiry is a moment in time.
 */
final readonly class ApiToken
{
    /** @param string[] $abilities reserved for scoping; every active token can read today */
    public function __construct(
        public int $id,
        public string $name,
        public array $abilities,
        public ?string $lastUsedAt,
        public int $usedCount = 0,
        public ?string $lastUsedIp = null,
        public ?string $expiresAt = null,
        public ?string $revokedAt = null,
        public ?string $pausedAt = null,
    ) {
    }

    public function isRevoked(): bool
    {
        return $this->revokedAt !== null;
    }

    /** Paused *and not* revoked — revocation is the stronger, terminal state. */
    public function isPaused(): bool
    {
        return $this->pausedAt !== null && $this->revokedAt === null;
    }

    public function isExpired(?int $now = null): bool
    {
        return $this->expiresAt !== null && strtotime($this->expiresAt) <= ($now ?? time());
    }

    /** Only an active token authenticates; the resolver rejects every other state. */
    public function isActive(?int $now = null): bool
    {
        return !$this->isRevoked() && !$this->isPaused() && !$this->isExpired($now);
    }

    /** A single word for listings and the admin surface. */
    public function status(?int $now = null): string
    {
        return match (true) {
            $this->isRevoked()      => 'revoked',
            $this->isExpired($now)  => 'expired',
            $this->isPaused()       => 'paused',
            default                 => 'active',
        };
    }
}

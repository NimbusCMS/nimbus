<?php

declare(strict_types=1);

namespace Nimbus\Api;

/**
 * The authenticated machine actor behind an API request.
 *
 * A token is a *standalone* principal (ADR 0006): its authority is its own
 * granted scopes, not a user's. It answers one question — "may I perform this
 * action on this resource?" — and answers it deny-by-default.
 *
 * A scope is `resource:action`, where resource is a collection handle or `*`
 * and action is `read` or `write` (only `read` is exercised while the API is
 * read-only).
 */
final readonly class TokenPrincipal
{
    /** @param string[] $scopes granted scopes, each `resource:action` */
    public function __construct(
        public int $tokenId,
        public string $name,
        public array $scopes,
    ) {
    }

    /**
     * Build the principal for a freshly-resolved token, applying the legacy
     * compatibility grant: a token minted before scopes existed (no abilities)
     * keeps read-all during the read-only era. This grant is removed when the
     * write API lands (ADR 0006), so keeping it in one place makes it a
     * one-line deletion later.
     */
    public static function fromToken(ApiToken $token): self
    {
        $scopes = $token->abilities === [] ? ['*:read'] : $token->abilities;

        return new self($token->id, $token->name, $scopes);
    }

    /** May this token perform $action on $resource? Deny-by-default; `*` grants every resource. */
    public function can(string $resource, string $action): bool
    {
        return in_array("{$resource}:{$action}", $this->scopes, true)
            || in_array("*:{$action}", $this->scopes, true);
    }
}

<?php

declare(strict_types=1);

namespace Nimbus\Api;

use Nimbus\Auth\Authorizer;

/**
 * The authenticated machine actor behind an API request.
 *
 * A token is a *standalone* principal (ADR 0006): its authority is its own
 * granted scopes, not a user's. It answers one question — "may I perform this
 * action on this resource?" — and answers it deny-by-default.
 *
 * A scope is `resource:action`, where resource is a collection handle, a
 * management capability (`schema`, `media`, `users`, `tokens`, `settings`), or
 * `*`, and action is `read` or `write`. The bare scope `admin` is a super-grant
 * — it permits every action on every resource. These management capabilities
 * are deliberately the atoms of a future roles system (ADR 0009): a role will
 * be a named bundle of them, so the model is designed now to slot RBAC in later
 * without rework.
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

    /** May this token perform $action on $resource? The shared, deny-by-default decision ({@see Authorizer}). */
    public function can(string $resource, string $action): bool
    {
        return Authorizer::can($this->scopes, $resource, $action);
    }
}

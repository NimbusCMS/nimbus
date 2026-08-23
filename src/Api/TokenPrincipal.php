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
     * Build the principal from a token's explicit abilities. Deny-by-default: no
     * abilities means no scopes (the legacy "empty → read-all" compat grant from
     * ADR 0006 was removed once the write API landed and role-bound tokens
     * arrived — see ADR 0011; keeping it would turn deleting a role into a
     * read-all *grant*). Role capabilities are unioned in by
     * {@see ApiTokenRepository::principalFor()}, the resolution boundary.
     */
    public static function fromToken(ApiToken $token): self
    {
        return new self($token->id, $token->name, $token->abilities);
    }

    /** May this token perform $action on $resource? The shared, deny-by-default decision ({@see Authorizer}). */
    public function can(string $resource, string $action): bool
    {
        return Authorizer::can(array_values($this->scopes), $resource, $action);
    }
}

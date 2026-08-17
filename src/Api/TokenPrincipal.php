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
    /**
     * The management capabilities (ADR 0009). They live in the `resource:action`
     * namespace like collection scopes, but they grant power over the whole
     * install, so the content wildcard `*:action` must NOT reach them — only an
     * exact grant or `admin` does. Otherwise "write all my content" (`*:write`)
     * would silently become "create users, mint tokens, change settings".
     */
    private const MANAGEMENT = ['schema', 'media', 'users', 'tokens', 'settings'];

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

    /**
     * May this token perform $action on $resource? Deny-by-default.
     *
     * - `admin` is the one cross-cutting super-grant — every action, every
     *   resource, content and management alike.
     * - An exact `{resource}:{action}` grant always suffices.
     * - The content wildcard `*:{action}` grants that action on every
     *   *collection*, but deliberately never on a management capability
     *   (schema/media/users/tokens/settings): those escalate privilege and must
     *   be granted explicitly. So a `*:write` token can edit any collection yet
     *   cannot mint tokens or create users.
     */
    public function can(string $resource, string $action): bool
    {
        if (in_array('admin', $this->scopes, true)) {
            return true;
        }
        if (in_array("{$resource}:{$action}", $this->scopes, true)) {
            return true;
        }
        if (in_array($resource, self::MANAGEMENT, true)) {
            return false;
        }
        return in_array("*:{$action}", $this->scopes, true);
    }
}

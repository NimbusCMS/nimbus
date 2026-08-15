<?php

declare(strict_types=1);

namespace Nimbus\Api;

/**
 * The request-scoped carrier for the authenticated API principal.
 *
 * The mirror of what the session `Auth` service is to the admin: a small,
 * explicitly-injected object that answers "who is calling?" for the API. The
 * difference is only the source — there is no session, so `ApiAuthMiddleware`
 * resolves the bearer token once and *establishes* the principal here, and the
 * controllers that were handed the same instance read it back.
 *
 * Request-scoped and explicit on purpose (ADR 0006): one instance per request,
 * passed by construction — never a global, a singleton, or a service locator.
 */
final class ApiAuthContext
{
    private ?TokenPrincipal $principal = null;

    /** Called once, by the middleware, after the token resolves. */
    public function establish(TokenPrincipal $principal): void
    {
        $this->principal = $principal;
    }

    /** The authenticated principal, or null before the middleware has run / on an unauthenticated request. */
    public function principal(): ?TokenPrincipal
    {
        return $this->principal;
    }
}

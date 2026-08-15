<?php

declare(strict_types=1);

namespace Nimbus\Http\Middleware;

use Nimbus\Api\ApiAuthContext;
use Nimbus\Api\ApiResponse;
use Nimbus\Api\ApiTokenRepository;
use Nimbus\Api\TokenPrincipal;
use Nimbus\Http\Request;
use Nimbus\Http\Response;

/**
 * Gates the API on a bearer token. Applied to the /api route group, the mirror
 * of AuthMiddleware for the admin — but it answers in JSON, not a redirect,
 * because an API client has nowhere to be redirected to.
 *
 * This is the single resolution point: it authenticates the token (the resolver
 * rejects a revoked, expired or paused one as simply "not valid"), records the
 * use, and *establishes* the principal on the request-scoped ApiAuthContext for
 * the controllers to read. Per-token scopes travel on the principal but are not
 * yet enforced — that arrives with scope enforcement in a later slice.
 */
final class ApiAuthMiddleware
{
    public function __construct(
        private ApiTokenRepository $tokens,
        private ApiAuthContext $context,
    ) {
    }

    public function __invoke(Request $request): ?Response
    {
        $plain = $request->bearerToken();
        if ($plain === null) {
            return ApiResponse::unauthorized('Provide an API token as: Authorization: Bearer <token>.');
        }

        $token = $this->tokens->findByPlaintext($plain);
        if ($token === null) {
            return ApiResponse::unauthorized('That API token is not valid.');
        }

        $this->tokens->touch($token->id, $request->ip());
        $this->context->establish(new TokenPrincipal($token->id, $token->name, $token->abilities));
        return null; // authenticated — proceed
    }
}

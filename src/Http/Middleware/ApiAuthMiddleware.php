<?php

declare(strict_types=1);

namespace Nimbus\Http\Middleware;

use Nimbus\Api\ApiResponse;
use Nimbus\Api\ApiTokenRepository;
use Nimbus\Http\Request;
use Nimbus\Http\Response;

/**
 * Gates the API on a bearer token. Applied to the /api route group, the mirror
 * of AuthMiddleware for the admin — but it answers in JSON, not a redirect,
 * because an API client has nowhere to be redirected to.
 *
 * A valid token is all this slice requires; per-token scopes (the abilities
 * column) are reserved for later. A used token's last_used_at is stamped so an
 * operator can see which tokens are live.
 */
final class ApiAuthMiddleware
{
    public function __construct(private ApiTokenRepository $tokens)
    {
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

        $this->tokens->touch($token->id);
        return null; // authenticated — proceed
    }
}

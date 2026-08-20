<?php

declare(strict_types=1);

namespace Nimbus\Http\Middleware;

use Nimbus\Api\ApiAuthContext;
use Nimbus\Api\ApiResponse;
use Nimbus\Api\ApiTokenRepository;
use Nimbus\Http\Request;
use Nimbus\Http\Response;
use Nimbus\Support\CoreEvents;
use Nimbus\Support\EventDispatcher;

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
        private EventDispatcher $events,
    ) {
    }

    public function __invoke(Request $request): ?Response
    {
        $plain = $request->bearerToken();
        if ($plain === null) {
            $this->announceRejection($request, 'missing');
            return ApiResponse::unauthorized('Provide an API token as: Authorization: Bearer <token>.');
        }

        $token = $this->tokens->findByPlaintext($plain);
        if ($token === null) {
            $this->announceRejection($request, 'invalid');
            return ApiResponse::unauthorized('That API token is not valid.');
        }

        $this->tokens->touch($token->id, $request->ip());
        $this->context->establish($this->tokens->principalFor($token));
        return null; // authenticated — proceed
    }

    /** Best-effort, isolated: an observer (audit log) can hear the refusal; the caller never does. */
    private function announceRejection(Request $request, string $reason): void
    {
        $this->events->emitBestEffort(CoreEvents::API_TOKEN_REJECTED, [
            'reason' => $reason,
            'ip'     => $request->ip(),
            'path'   => $request->path,
            'at'     => date('Y-m-d H:i:s'),
        ]);
    }
}

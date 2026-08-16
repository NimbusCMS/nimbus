<?php

declare(strict_types=1);

namespace Nimbus\Http\Middleware;

use Nimbus\Api\ApiResponse;
use Nimbus\Http\ApiRateLimiter;
use Nimbus\Http\Request;
use Nimbus\Http\Response;

/**
 * Refuses a request once its key has exceeded `limit` hits per `window` seconds.
 *
 * One class, two deployments in the API group (see ApiController): a per-IP flood
 * guard *before* auth (so a flood of no-token / invalid-token requests is turned
 * away cheaply), and a per-token quota *after* auth (fair use per client). The
 * key resolver decides the bucket; returning null means "not my concern here"
 * (e.g. the token limiter before a principal exists) and the request passes.
 */
final class RateLimitMiddleware
{
    /** @var callable(Request): ?string */
    private $key;

    /** @param callable(Request): ?string $key */
    public function __construct(
        private ApiRateLimiter $limiter,
        private int $limit,
        private int $window,
        callable $key,
    ) {
        $this->key = $key;
    }

    public function __invoke(Request $request): ?Response
    {
        if ($this->limit <= 0) {
            return null; // disabled
        }
        $key = ($this->key)($request);
        if ($key === null) {
            return null; // nothing to key on at this stage
        }

        $result = $this->limiter->hit($key, $this->limit, $this->window);
        if (!$result->exceeded) {
            return null;
        }

        return ApiResponse::error(429, 'rate_limited', 'Too many requests — slow down.')
            ->withHeader('Retry-After', (string) $result->retryAfter)
            ->withHeader('X-RateLimit-Limit', (string) $result->limit)
            ->withHeader('X-RateLimit-Remaining', '0')
            ->withHeader('X-RateLimit-Reset', (string) $result->reset);
    }
}

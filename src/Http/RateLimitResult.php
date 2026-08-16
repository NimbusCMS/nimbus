<?php

declare(strict_types=1);

namespace Nimbus\Http;

/** The outcome of one rate-limit hit: whether it was over the limit, and the numbers for the response headers. */
final readonly class RateLimitResult
{
    public function __construct(
        public bool $exceeded,
        public int $limit,
        public int $remaining,
        /** Unix time when the current window resets. */
        public int $reset,
        /** Seconds until the window resets (for Retry-After); at least 1. */
        public int $retryAfter,
    ) {
    }
}

<?php

declare(strict_types=1);

namespace Nimbus\Auth\OAuth;

/**
 * The outcome of an OAuth callback (ADR 0012). `userId` is set only for
 * {@see OAuthOutcome::SignedIn}; `next` is the validated internal redirect target
 * for a sign-in; `providerLabel` is for display in a notice.
 */
final readonly class OAuthResult
{
    public function __construct(
        public OAuthOutcome $outcome,
        public ?int $userId = null,
        public string $next = '/admin',
        public string $providerLabel = '',
    ) {
    }
}

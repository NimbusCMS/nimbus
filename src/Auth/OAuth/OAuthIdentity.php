<?php

declare(strict_types=1);

namespace Nimbus\Auth\OAuth;

/**
 * The identity a provider asserts for the authenticated end-user. `providerUserId`
 * is the **immutable subject** we key on (Google `sub`, GitHub numeric `id`);
 * `email`/`name` are display/for-later-phases only. In Phase 1 email is never a
 * matching key — see ADR 0012.
 */
final readonly class OAuthIdentity
{
    public function __construct(
        public string $providerUserId,
        public string $email,
        public bool $emailVerified,
        public string $name,
    ) {
    }
}

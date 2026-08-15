<?php

declare(strict_types=1);

namespace Nimbus\Api;

/**
 * The authenticated machine actor behind an API request.
 *
 * A token is a *standalone* principal (ADR 0006): its authority is its own
 * granted scopes, not a user's. This slice only *carries* the scopes from the
 * resolved token to the controller — the `can(resource, action)` decision, and
 * anything consulting it, lands with scope enforcement in a later slice.
 */
final readonly class TokenPrincipal
{
    /** @param string[] $scopes granted scopes, carried for later enforcement */
    public function __construct(
        public int $tokenId,
        public string $name,
        public array $scopes,
    ) {
    }
}

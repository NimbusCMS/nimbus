<?php

declare(strict_types=1);

namespace Nimbus\Auth;

/**
 * The authorization view of a signed-in user (ADR 0011): the union of the
 * capabilities of the roles assigned to them, judged by the same {@see Authorizer}
 * as an API token — so a person and an agent are authorized identically.
 *
 * Built from {@see RoleRepository::capabilitiesForUser()}. It carries authority,
 * not identity (that stays on {@see User}).
 */
final readonly class UserPrincipal
{
    /** @param list<string> $capabilities the union of the user's roles */
    public function __construct(
        public int $userId,
        public array $capabilities,
    ) {
    }

    public function can(string $resource, string $action): bool
    {
        return Authorizer::can($this->capabilities, $resource, $action);
    }
}

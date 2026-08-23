<?php

declare(strict_types=1);

namespace Nimbus\Auth;

use Nimbus\Content\Collection;
use Nimbus\Content\Permissions;

/**
 * The admin's authorization gate (ADR 0011). It answers "may the signed-in user
 * do this?" from the union of their roles' capabilities, through the same
 * {@see Authorizer} that judges API tokens — so people and agents are authorized
 * by one rule.
 *
 * Two deliberate behaviours:
 * - **Memoized** per request: the capability union is resolved once.
 * - **Legacy fallback:** until the roles system is seeded (`nb_roles` empty — a
 *   freshly-migrated install before `roles:seed`), it delegates to the old
 *   {@see Permissions} path *verbatim*, so an un-seeded upgrade authorizes exactly
 *   as before and no one is locked out. The instant roles exist, capabilities
 *   govern. The fallback is not attacker-reachable: system roles are undeletable,
 *   so the table can't be emptied through the app.
 */
final class Gate
{
    /** @var list<string>|null */
    private ?array $capabilities = null;
    private ?bool $seeded = null;

    public function __construct(
        private RoleRepository $roles,
        private Auth $auth,
    ) {
    }

    /** May the user perform $action on a management/structural resource? */
    public function can(string $resource, string $action): bool
    {
        $user = $this->auth->user();
        if ($user === null) {
            return false;
        }
        if (!$this->seeded()) {
            // Legacy: every structural action was administrators-only.
            return Permissions::isAdmin($user);
        }
        return Authorizer::can($this->capabilities(), $resource, $action);
    }

    /** May the user manage (write) this collection's entries? */
    public function manages(Collection $collection): bool
    {
        $user = $this->auth->user();
        if ($user === null) {
            return false;
        }
        if (!$this->seeded()) {
            return Permissions::canManage($user, $collection);
        }
        return Authorizer::can($this->capabilities(), $collection->handle, 'write');
    }

    /**
     * Does the user *hold* this capability? Used for subset-only checks — you can
     * only grant (into a role, or by assigning a role) what you hold. `admin`
     * holds everything.
     */
    public function holds(string $capability): bool
    {
        // Seeded: one shared predicate over the user's capability union.
        if ($this->seeded()) {
            return Authorizer::holds($this->capabilities(), $capability);
        }
        // Unseeded legacy fallback: structural authority was administrators-only,
        // so a user holds a capability iff they are a legacy admin.
        if ($capability === 'admin') {
            $user = $this->auth->user();
            return $user !== null && Permissions::isAdmin($user);
        }
        $parts = explode(':', $capability, 2);
        return count($parts) === 2 && $parts[1] !== '' && $this->can($parts[0], $parts[1]);
    }

    private function seeded(): bool
    {
        return $this->seeded ??= $this->roles->hasAny();
    }

    /** @return list<string> */
    private function capabilities(): array
    {
        if ($this->capabilities !== null) {
            return $this->capabilities;
        }
        $user = $this->auth->user();
        return $this->capabilities = ($user === null ? [] : $this->roles->capabilitiesForUser($user->id));
    }
}

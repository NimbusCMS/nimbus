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
     * May the user read (browse) this collection's entries? (ADR 0011.) A content
     * write implies read, so any manager passes. Un-seeded content read was never
     * gated — every signed-in user could browse — so the legacy fallback preserves
     * that; routing it through {@see can()} (admin-only when un-seeded) would lock
     * non-admins out of browsing on an un-seeded upgrade.
     */
    public function reads(Collection $collection): bool
    {
        $user = $this->auth->user();
        if ($user === null) {
            return false;
        }
        if (!$this->seeded()) {
            return true; // legacy: browsing was open to any signed-in user
        }
        return Authorizer::can($this->capabilities(), $collection->handle, 'read');
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

    /**
     * May the user open an admin page gated by $capability? `null` = login-only
     * (any signed-in user). `admin` and a core management capability behave like
     * {@see holds()}.
     *
     * The load-bearing rule is for a **plugin** capability (a namespaced resource,
     * ADR 0015): it is honoured only when it is a *frozen, declared* management
     * capability ({@see Authorizer::isManagement()} — sealed at boot). An unknown
     * or undeclared resource is refused to everyone but `admin`, so a content
     * `*:write` wildcard can never satisfy a plugin page's gate (the money-grade
     * asymmetry the MCP path already forbids — ADR 0020). This is the one gate the
     * nav (visibility) and the plugin route (enforcement) both call, so they can
     * never drift.
     */
    public function holdsPageGate(?string $capability): bool
    {
        if ($capability === null) {
            return true;
        }
        if ($capability === 'admin') {
            return $this->holds('admin');
        }
        [$resource] = explode(':', $capability, 2);
        // A non-core resource must be a frozen plugin management capability; if it
        // isn't (typo, undeclared, or a content-shaped handle), only `admin` opens
        // the page — the content wildcard is never allowed to reach it.
        if (!in_array($resource, Authorizer::MANAGEMENT, true) && !Authorizer::isManagement($resource)) {
            return $this->holds('admin');
        }
        return $this->holds($capability);
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

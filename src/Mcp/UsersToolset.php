<?php

declare(strict_types=1);

namespace Nimbus\Mcp;

use Nimbus\Api\EntryOpContext;
use Nimbus\Api\TokenPrincipal;
use Nimbus\Auth\Authorizer;
use Nimbus\Auth\Password;
use Nimbus\Auth\Role;
use Nimbus\Auth\RoleRepository;
use Nimbus\Auth\UserRepository;
use Nimbus\Database\Connection;
use Nimbus\Support\CoreEvents;
use Nimbus\Support\EventDispatcher;

/**
 * The MCP user-management tools (ADR 0009), gated on `users:write`.
 *
 * Authority is the roles system (ADR 0011): a user's capabilities are the union
 * of their assigned `nb_user_roles` — so these tools assign **roles**, never the
 * legacy `nb_users.role` string (which is left at a least-privilege `author`
 * placeholder and is no longer authoritative post-seed). `list_roles` lets an
 * agent discover assignable role names.
 *
 * Three rules matter here:
 * - **Subset-only (no escalation):** you can only grant a role whose every
 *   capability the calling token itself holds — the same check the admin UI and
 *   `TokensToolset` apply, via the shared {@see Authorizer::holds}. `set_role`
 *   checks *both* the new role and the target's current roles, so a lesser
 *   manager can neither elevate a user nor strip a superior's role.
 * - **Secrets:** a password is hashed immediately (argon2id) and never stored or
 *   logged in plaintext; an omitted one is generated and returned **once**.
 * - **Last admin:** demoting the last holder of the `admin` role is refused, on
 *   the same `nb_user_roles` count the admin UI uses (no second counter).
 */
final class UsersToolset implements Toolset
{
    private const CAPABILITY = 'users';
    /** @var list<string> */
    private const TOOLS = ['list_users', 'list_roles', 'create_user', 'set_role'];

    public function __construct(
        private UserRepository $users,
        private RoleRepository $roles,
        private Connection $db,
        private EventDispatcher $events,
    ) {
    }

    /** @return list<array<string,mixed>> */
    public function definitions(TokenPrincipal $principal): array
    {
        if (!$principal->can(self::CAPABILITY, 'write')) {
            return [];
        }
        return [$this->listDefinition(), $this->listRolesDefinition(), $this->createDefinition(), $this->setRoleDefinition()];
    }

    /**
     * @param array<string,mixed> $args
     * @return array<string,mixed>|null
     */
    public function call(string $name, array $args, TokenPrincipal $principal, EntryOpContext $ctx): ?array
    {
        if (!in_array($name, self::TOOLS, true)) {
            return null;
        }
        if (!$principal->can(self::CAPABILITY, 'write')) {
            $this->events->emitBestEffort(CoreEvents::API_ACCESS_DENIED, [
                'token_id' => $principal->tokenId, 'token_name' => $principal->name,
                'resource' => self::CAPABILITY, 'action' => 'write',
                'ip' => $ctx->ip, 'path' => $ctx->path, 'at' => date('Y-m-d H:i:s'),
            ]);
            throw new McpError(JsonRpc::INVALID_PARAMS, "Unknown tool \"{$name}\".");
        }

        return match ($name) {
            'list_users'  => $this->listUsers(),
            'list_roles'  => $this->listRoles(),
            'create_user' => $this->createUser($args, $principal, $ctx),
            'set_role'    => $this->setRole($args, $principal, $ctx),
        };
    }

    /** @return array<string,mixed> */
    private function listUsers(): array
    {
        $rows = array_map(
            fn ($u): array => [
                'id'    => $u->id,
                'email' => $u->email,
                'name'  => $u->name,
                'roles' => array_map(static fn (Role $r): string => $r->name, $this->roles->rolesForUser($u->id)),
            ],
            $this->users->all(),
        );
        return ToolResult::ok(['data' => $rows]);
    }

    /** @return array<string,mixed> */
    private function listRoles(): array
    {
        $rows = array_map(
            static fn (Role $r): array => ['name' => $r->name, 'capabilities' => $r->capabilities],
            $this->roles->all(),
        );
        return ToolResult::ok(['data' => $rows]);
    }

    /**
     * @param array<string,mixed> $args
     * @return array<string,mixed>
     */
    private function createUser(array $args, TokenPrincipal $principal, EntryOpContext $ctx): array
    {
        $email = strtolower(trim($this->str($args, 'email')));
        if ($email === '' || !str_contains($email, '@')) {
            return ToolResult::error('A valid email is required.', 'invalid');
        }
        if ($this->users->emailExists($email)) {
            return ToolResult::error("A user with email \"{$email}\" already exists.", 'invalid');
        }

        $role = $this->resolveRole($this->str($args, 'role') !== '' ? $this->str($args, 'role') : 'editor');
        if (is_string($role)) {
            return ToolResult::error($role, 'invalid'); // resolution message
        }
        // Subset-only: refuse to grant a role carrying a capability the caller lacks.
        $ungrantable = $this->firstUngrantableCapability($principal, $role);
        if ($ungrantable !== null) {
            return ToolResult::error("You cannot assign the \"{$role->name}\" role — it grants a capability you do not hold: \"{$ungrantable}\".", 'forbidden');
        }

        $name = trim($this->str($args, 'name'));
        if ($name === '') {
            $name = ucfirst(explode('@', $email)[0]);
        }

        // Password: the caller's (strength-checked) or a generated strong one to
        // return once. Hashed before it touches the database; never in the event.
        $provided  = $this->str($args, 'password');
        $generated = null;
        if ($provided !== '') {
            if (Password::isWeak($provided)) {
                return ToolResult::error('That password is too weak (at least ' . Password::MIN_LENGTH . ' non-default characters).', 'invalid');
            }
            $plain = $provided;
        } else {
            $generated = $this->strongPassword();
            $plain     = $generated;
        }

        // Create the user and its role assignment atomically. The legacy
        // nb_users.role is a least-privilege 'author' placeholder — never 'admin'
        // (which would elevate under the un-seeded fallback); authority is the
        // assigned role.
        $roleId = $role->id;
        $hash   = Password::hash($plain);
        $id     = $this->db->transaction(function () use ($name, $email, $hash, $roleId): int {
            $newId = $this->users->create($name, $email, $hash, 'author');
            $this->roles->syncUserRoles($newId, [$roleId]);
            return $newId;
        });
        $this->announce($principal, $ctx, 'create_user', "{$email}:{$role->name}");

        $result = ['user' => ['id' => $id, 'email' => $email, 'name' => $name, 'roles' => [$role->name]]];
        if ($generated !== null) {
            $result['generated_password'] = $generated; // show-once
        }
        return ToolResult::ok($result);
    }

    /**
     * @param array<string,mixed> $args
     * @return array<string,mixed>
     */
    private function setRole(array $args, TokenPrincipal $principal, EntryOpContext $ctx): array
    {
        $email = strtolower(trim($this->str($args, 'email')));
        $user  = $email !== '' ? $this->users->findByEmail($email) : null;
        if ($user === null) {
            return ToolResult::error('No such user.', 'not_found');
        }

        $role = $this->resolveRole($this->str($args, 'role'));
        if (is_string($role)) {
            return ToolResult::error($role, 'invalid');
        }

        // Subset-only, both directions: you cannot assign a role that grants more
        // than you hold, nor replace (strip) a role the target already holds that
        // grants more than you hold — so a lesser manager can neither escalate a
        // user nor demote a superior.
        $existing = $this->roles->rolesForUser($user->id);
        $blocker  = $this->firstUngrantableRole($principal, array_merge([$role], $existing));
        if ($blocker !== null) {
            return ToolResult::error("You cannot grant or change the \"{$blocker['role']}\" role — it involves a capability you do not hold: \"{$blocker['capability']}\".", 'forbidden');
        }

        // Never strand an install without an admin — counted on the same role
        // assignment the admin UI uses (one source of truth).
        $adminRole = $this->roles->findByName('admin');
        if ($adminRole !== null
            && $role->id !== $adminRole->id
            && $this->hasRole($existing, $adminRole->id)
            && $this->roles->assignedUserCount($adminRole->id) <= 1) {
            return ToolResult::error('This is the only admin; promote another user to admin before changing this role.', 'invalid');
        }

        $this->roles->syncUserRoles($user->id, [$role->id]);
        $this->announce($principal, $ctx, 'set_role', "{$user->email}:{$role->name}");
        return ToolResult::ok(['user' => ['id' => $user->id, 'email' => $user->email, 'roles' => [$role->name]]]);
    }

    /**
     * Resolve a role name to a {@see Role}, or return a human error string when it
     * can't (roles unseeded, or no such role).
     */
    private function resolveRole(string $name): Role|string
    {
        if (!$this->roles->hasAny()) {
            return 'Roles are not configured yet. Run `roles:seed` before managing users.';
        }
        $role = $this->roles->findByName($name);
        if ($role === null) {
            $names = implode(', ', array_map(static fn (Role $r): string => $r->name, $this->roles->all()));
            return "No role named \"{$name}\". Known roles: {$names}. (See list_roles.)";
        }
        return $role;
    }

    /** The first capability of $role the caller does not hold, or null. */
    private function firstUngrantableCapability(TokenPrincipal $principal, Role $role): ?string
    {
        foreach ($role->capabilities as $capability) {
            if (!Authorizer::holds(array_values($principal->scopes), $capability)) {
                return $capability;
            }
        }
        return null;
    }

    /**
     * Across a set of roles, the first {role, capability} pair the caller cannot
     * grant, or null.
     *
     * @param list<Role> $roles
     * @return array{role:string,capability:string}|null
     */
    private function firstUngrantableRole(TokenPrincipal $principal, array $roles): ?array
    {
        foreach ($roles as $role) {
            $capability = $this->firstUngrantableCapability($principal, $role);
            if ($capability !== null) {
                return ['role' => $role->name, 'capability' => $capability];
            }
        }
        return null;
    }

    /** @param list<Role> $roles */
    private function hasRole(array $roles, int $roleId): bool
    {
        foreach ($roles as $role) {
            if ($role->id === $roleId) {
                return true;
            }
        }
        return false;
    }

    private function strongPassword(): string
    {
        // URL-safe, ~22 chars of entropy; well past the weak-password floor.
        return rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=');
    }

    private function announce(TokenPrincipal $principal, EntryOpContext $ctx, string $action, string $target): void
    {
        $this->events->emitBestEffort(CoreEvents::API_MANAGEMENT_WRITTEN, [
            'token_id' => $principal->tokenId, 'token_name' => $principal->name,
            'capability' => self::CAPABILITY, 'action' => $action, 'target' => $target,
            'ip' => $ctx->ip, 'path' => $ctx->path, 'at' => date('Y-m-d H:i:s'),
        ]);
    }

    /** @param array<string,mixed> $args */
    private function str(array $args, string $key): string
    {
        return is_string($args[$key] ?? null) ? $args[$key] : '';
    }

    // ------------------------------------------------------------- definitions

    /** @return array<string,mixed> */
    private function listDefinition(): array
    {
        return $this->tool('list_users', 'List CMS users (id, email, name, assigned roles).', ['type' => 'object', 'properties' => new \stdClass()]);
    }

    /** @return array<string,mixed> */
    private function listRolesDefinition(): array
    {
        return $this->tool('list_roles', 'List the roles you can assign (name + capabilities). Use a name with create_user / set_role.', ['type' => 'object', 'properties' => new \stdClass()]);
    }

    /** @return array<string,mixed> */
    private function createDefinition(): array
    {
        return $this->tool('create_user', 'Create a CMS user and assign a role (default: editor). If no password is given, a strong one is generated and returned once. You can only assign a role whose capabilities you hold.', [
            'type'       => 'object',
            'required'   => ['email'],
            'properties' => [
                'email'    => ['type' => 'string'],
                'name'     => ['type' => 'string'],
                'role'     => ['type' => 'string', 'description' => 'A role name (see list_roles). Defaults to "editor".'],
                'password' => ['type' => 'string', 'description' => 'Optional; at least ' . Password::MIN_LENGTH . ' non-default characters. Omit to auto-generate.'],
            ],
        ]);
    }

    /** @return array<string,mixed> */
    private function setRoleDefinition(): array
    {
        return $this->tool('set_role', "Set a user's role by email (replaces their current role assignments). You can only grant, or change away from, roles whose capabilities you hold.", [
            'type'       => 'object',
            'required'   => ['email', 'role'],
            'properties' => [
                'email' => ['type' => 'string'],
                'role'  => ['type' => 'string', 'description' => 'A role name (see list_roles).'],
            ],
        ]);
    }

    /**
     * @param array<string,mixed> $inputSchema
     * @return array<string,mixed>
     */
    private function tool(string $name, string $description, array $inputSchema): array
    {
        return ['name' => $name, 'description' => $description, 'inputSchema' => $inputSchema];
    }
}

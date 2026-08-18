<?php

declare(strict_types=1);

namespace Nimbus\Mcp;

use Nimbus\Api\EntryOpContext;
use Nimbus\Api\TokenPrincipal;
use Nimbus\Auth\Password;
use Nimbus\Auth\UserRepository;
use Nimbus\Content\Permissions;
use Nimbus\Support\CoreEvents;
use Nimbus\Support\EventDispatcher;

/**
 * The MCP user-management tools (ADR 0009, Slice 6), gated on `users:write`.
 *
 * Two secret-handling rules matter here: a password is **hashed immediately**
 * (argon2id) and never stored or logged in plaintext, and when the caller omits
 * one a strong password is generated and returned **once** in the result (never
 * in the audit event). Demoting the last admin is refused so an install can't be
 * locked out of its own back door.
 */
final class UsersToolset implements Toolset
{
    private const CAPABILITY = 'users';
    /** @var list<string> */
    private const TOOLS = ['list_users', 'create_user', 'set_role'];

    public function __construct(
        private UserRepository $users,
        private EventDispatcher $events,
    ) {
    }

    /** @return list<array<string,mixed>> */
    public function definitions(TokenPrincipal $principal): array
    {
        if (!$principal->can(self::CAPABILITY, 'write')) {
            return [];
        }
        return [$this->listDefinition(), $this->createDefinition(), $this->setRoleDefinition()];
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
            'create_user' => $this->createUser($args, $principal, $ctx),
            'set_role'    => $this->setRole($args, $principal, $ctx),
        };
    }

    /** @return array<string,mixed> */
    private function listUsers(): array
    {
        $rows = array_map(
            static fn ($u): array => ['id' => $u->id, 'email' => $u->email, 'name' => $u->name, 'role' => $u->role],
            $this->users->all(),
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

        $role = $this->str($args, 'role') !== '' ? $this->str($args, 'role') : 'editor';
        if (!in_array($role, Permissions::ROLES, true)) {
            return ToolResult::error('Unknown role. Valid roles: ' . implode(', ', Permissions::ROLES) . '.', 'invalid');
        }
        $name = trim($this->str($args, 'name'));
        if ($name === '') {
            $name = ucfirst(explode('@', $email)[0]);
        }

        // Password: use the caller's (strength-checked) or generate a strong one
        // to return once. Either way it is hashed before it touches the database
        // and never appears in the audit event.
        $provided  = $this->str($args, 'password');
        $generated = null;
        if ($provided !== '') {
            if (Password::isWeak($provided)) {
                return ToolResult::error('That password is too weak (at least 8 non-default characters).', 'invalid');
            }
            $plain = $provided;
        } else {
            $generated = $this->strongPassword();
            $plain     = $generated;
        }

        $id = $this->users->create($name, $email, Password::hash($plain), $role);
        $this->announce($principal, $ctx, 'create_user', $email);

        $result = ['user' => ['id' => $id, 'email' => $email, 'name' => $name, 'role' => $role]];
        if ($generated !== null) {
            // Show-once: the only time this plaintext exists outside the caller.
            $result['generated_password'] = $generated;
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
        $role = $this->str($args, 'role');
        if (!in_array($role, Permissions::ROLES, true)) {
            return ToolResult::error('Unknown role. Valid roles: ' . implode(', ', Permissions::ROLES) . '.', 'invalid');
        }

        // Never strand an install without an admin.
        if ($user->role === 'admin' && $role !== 'admin' && $this->users->countByRole('admin') <= 1) {
            return ToolResult::error('This is the only admin; promote another user to admin before changing this role.', 'invalid');
        }

        $this->users->setRole($user->id, $role);
        $this->announce($principal, $ctx, 'set_role', "{$user->email}:{$role}");
        return ToolResult::ok(['user' => ['id' => $user->id, 'email' => $user->email, 'role' => $role]]);
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
        return $this->tool('list_users', 'List CMS users (id, email, name, role).', ['type' => 'object', 'properties' => new \stdClass()]);
    }

    /** @return array<string,mixed> */
    private function createDefinition(): array
    {
        return $this->tool('create_user', 'Create a CMS user. If no password is given, a strong one is generated and returned once.', [
            'type'       => 'object',
            'required'   => ['email'],
            'properties' => [
                'email'    => ['type' => 'string'],
                'name'     => ['type' => 'string'],
                'role'     => ['type' => 'string', 'enum' => Permissions::ROLES],
                'password' => ['type' => 'string', 'description' => 'Optional; at least 8 non-default characters. Omit to auto-generate.'],
            ],
        ]);
    }

    /** @return array<string,mixed> */
    private function setRoleDefinition(): array
    {
        return $this->tool('set_role', "Change a user's role (by email).", [
            'type'       => 'object',
            'required'   => ['email', 'role'],
            'properties' => [
                'email' => ['type' => 'string'],
                'role'  => ['type' => 'string', 'enum' => Permissions::ROLES],
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

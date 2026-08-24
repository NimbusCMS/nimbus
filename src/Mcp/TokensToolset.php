<?php

declare(strict_types=1);

namespace Nimbus\Mcp;

use Nimbus\Api\ApiToken;
use Nimbus\Api\ApiTokenRepository;
use Nimbus\Api\EntryOpContext;
use Nimbus\Api\TokenPrincipal;
use Nimbus\Auth\Authorizer;
use Nimbus\Auth\RoleRepository;
use Nimbus\Support\CoreEvents;
use Nimbus\Support\EventDispatcher;

/**
 * The MCP API-token tools (ADR 0009, Slice 6), gated on `tokens:write`.
 *
 * Two controls define this surface. **No privilege escalation:** a token may
 * only mint scopes it already holds — so a `tokens:write` token cannot forge
 * itself an `admin` (only an admin can grant admin), which is exactly the
 * subset-only rule a roles system needs. **Show-once secrets:** the minted
 * plaintext is returned in the result and nowhere else — never persisted (only
 * its hash), never in the audit event, never logged.
 */
final class TokensToolset implements Toolset
{
    private const CAPABILITY = 'tokens';
    /** @var list<string> */
    private const TOOLS = ['list_tokens', 'mint_token', 'revoke_token', 'pause_token', 'resume_token'];

    public function __construct(
        private ApiTokenRepository $tokens,
        private RoleRepository $roles,
        private EventDispatcher $events,
    ) {
    }

    /** @return list<array<string,mixed>> */
    public function definitions(TokenPrincipal $principal): array
    {
        if (!$principal->can(self::CAPABILITY, 'write')) {
            return [];
        }
        return [$this->listDefinition(), $this->mintDefinition(), $this->lifecycleDefinition('revoke_token', 'Revoke a token permanently.'), $this->lifecycleDefinition('pause_token', 'Temporarily disable a token.'), $this->lifecycleDefinition('resume_token', 'Re-enable a paused token.')];
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
            'list_tokens'   => $this->listTokens(),
            'mint_token'    => $this->mintToken($args, $principal, $ctx),
            'revoke_token'  => $this->lifecycle('revoke', $args, $principal, $ctx),
            'pause_token'   => $this->lifecycle('pause', $args, $principal, $ctx),
            'resume_token'  => $this->lifecycle('resume', $args, $principal, $ctx),
        };
    }

    /** @return array<string,mixed> */
    private function listTokens(): array
    {
        // Resolve bound-role ids to names once, so a listing shows the role a
        // token draws its live capabilities from — not just its explicit scopes.
        $roleNames = [];
        foreach ($this->roles->all() as $role) {
            $roleNames[$role->id] = $role->name;
        }
        $rows = array_map(
            static fn (ApiToken $t): array => [
                'id'        => $t->id,
                'name'      => $t->name,
                'status'    => $t->status(),
                'scopes'    => $t->abilities,
                'role'      => $t->roleId !== null ? ($roleNames[$t->roleId] ?? null) : null,
                'used'      => $t->usedCount,
                'last_used' => $t->lastUsedAt,
                'expires'   => $t->expiresAt,
            ],
            $this->tokens->all(),
        );
        return ToolResult::ok(['data' => $rows]);
    }

    /**
     * @param array<string,mixed> $args
     * @return array<string,mixed>
     */
    private function mintToken(array $args, TokenPrincipal $principal, EntryOpContext $ctx): array
    {
        $name = trim($this->str($args, 'name'));
        if ($name === '') {
            return ToolResult::error('A token needs a name.', 'invalid');
        }

        $requested = is_array($args['scopes'] ?? null) ? $args['scopes'] : [];
        $scopes    = [];
        foreach ($requested as $scope) {
            if (!is_string($scope)) {
                continue;
            }
            $scope = trim($scope);
            if (!$this->validScope($scope)) {
                return ToolResult::error("Invalid scope \"{$scope}\" (e.g. posts:read, schema:write, *:read, or admin).", 'invalid');
            }
            // The escalation guard: you cannot grant what you do not hold.
            if (!$this->holds($principal, $scope)) {
                return ToolResult::error("You cannot grant a scope you do not hold: \"{$scope}\".", 'forbidden');
            }
            $scopes[] = $scope;
        }

        // Optionally bind to a role — its live capabilities. Subset-only applies
        // to *every* capability the role grants, so binding a powerful role is no
        // way around the escalation guard.
        $roleId   = null;
        $roleName = trim($this->str($args, 'role'));
        if ($roleName !== '') {
            $role = $this->roles->findByName($roleName);
            if ($role === null) {
                return ToolResult::error("No role named \"{$roleName}\".", 'invalid');
            }
            foreach ($role->capabilities as $capability) {
                if (!$this->holds($principal, $capability)) {
                    return ToolResult::error("You cannot mint a token as \"{$roleName}\" — it grants a capability you do not hold: \"{$capability}\".", 'forbidden');
                }
            }
            $roleId = $role->id;
        }

        if ($scopes === [] && $roleId === null) {
            return ToolResult::error('A token needs at least one scope or a role.', 'invalid');
        }

        $expiresAt = null;
        $days      = $this->intArg($args, 'expires_days', 0);
        if ($days > 0) {
            $expiresAt = date('Y-m-d H:i:s', time() + $days * 86400);
        }

        $plain = $this->tokens->create($name, $scopes, $expiresAt, $roleId);
        // Audit records what was granted, never the secret.
        $this->announce($principal, $ctx, 'mint_token', $name . ($roleName !== '' ? " (role: {$roleName})" : '') . ' [' . implode(', ', $scopes) . ']');

        return ToolResult::ok([
            'token'  => ['name' => $name, 'scopes' => $scopes, 'role' => $roleName !== '' ? $roleName : null, 'expires_at' => $expiresAt],
            'secret' => $plain, // show-once
        ]);
    }

    /**
     * @param array<string,mixed> $args
     * @return array<string,mixed>
     */
    private function lifecycle(string $action, array $args, TokenPrincipal $principal, EntryOpContext $ctx): array
    {
        $id = $this->intArg($args, 'id', 0);
        if ($id <= 0) {
            return ToolResult::error('A token id is required.', 'invalid');
        }
        // ADMIN-14b: a nonexistent id must fail, not report a phantom success —
        // and must NOT emit an audit event announcing a revoke that never
        // happened. Existence is checked first (not affected-rows), so an
        // idempotent re-revoke/pause/resume of a real token stays a success.
        if (!$this->tokens->exists($id)) {
            return ToolResult::error("No token with id {$id}.", 'not_found');
        }
        match ($action) {
            'revoke' => $this->tokens->revoke($id),
            'pause'  => $this->tokens->pause($id),
            default  => $this->tokens->resume($id),
        };
        $this->announce($principal, $ctx, "{$action}_token", (string) $id);
        return ToolResult::ok(['id' => $id, 'action' => $action]);
    }

    /** May this principal grant $scope? (admin grants anything; otherwise it must hold it.) */
    private function holds(TokenPrincipal $principal, string $scope): bool
    {
        return Authorizer::holds(array_values($principal->scopes), $scope);
    }

    private function validScope(string $scope): bool
    {
        return $scope === 'admin' || preg_match('/^(\*|[a-z0-9-]+):(read|write)$/', $scope) === 1;
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

    /** @param array<string,mixed> $args */
    private function intArg(array $args, string $key, int $default): int
    {
        return isset($args[$key]) && is_numeric($args[$key]) ? (int) $args[$key] : $default;
    }

    // ------------------------------------------------------------- definitions

    /** @return array<string,mixed> */
    private function listDefinition(): array
    {
        return $this->tool('list_tokens', 'List API tokens and their scopes/status — never the secret.', ['type' => 'object', 'properties' => new \stdClass()]);
    }

    /** @return array<string,mixed> */
    private function mintDefinition(): array
    {
        return $this->tool('mint_token', 'Mint an API token; the secret is returned once. Give explicit scopes, or bind it to a role (its live capabilities). You can only grant scopes/roles you already hold.', [
            'type'       => 'object',
            'required'   => ['name'],
            'properties' => [
                'name'         => ['type' => 'string'],
                'scopes'       => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'e.g. ["posts:read","posts:write"]. Only scopes you hold.'],
                'role'         => ['type' => 'string', 'description' => 'Optional; bind the token to a role — its capabilities apply live. Only a role whose capabilities you hold.'],
                'expires_days' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Optional; days until expiry.'],
            ],
        ]);
    }

    /** @return array<string,mixed> */
    private function lifecycleDefinition(string $name, string $description): array
    {
        return $this->tool($name, $description, [
            'type'       => 'object',
            'required'   => ['id'],
            'properties' => ['id' => ['type' => 'integer']],
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

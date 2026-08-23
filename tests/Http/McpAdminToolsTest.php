<?php

declare(strict_types=1);

namespace Nimbus\Tests\Http;

use Nimbus\Api\ApiTokenRepository;
use Nimbus\Auth\Password;
use Nimbus\Auth\RoleRepository;
use Nimbus\Auth\RoleSeeder;
use Nimbus\Auth\UserRepository;
use Nimbus\Content\CollectionRepository;
use Nimbus\Http\Request;

/**
 * MCP user + token management (ADR 0009, Slice 6). Proves the capability gates,
 * that a token can only mint scopes it already holds (no privilege escalation),
 * that secrets/passwords are returned once and never leak, and the safety rails
 * (weak password, duplicate email, last-admin demotion).
 */
final class McpAdminToolsTest extends HttpTestCase
{
    private ApiTokenRepository $tokens;
    private UserRepository $users;
    private RoleRepository $roles;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tokens = new ApiTokenRepository($this->db);
        $this->users  = new UserRepository($this->db);
        $this->roles  = new RoleRepository($this->db);
    }

    /** Seed the three system roles (admin/editor/author) — user tools need them. */
    private function seedRoles(): void
    {
        (new RoleSeeder($this->db, $this->roles, new CollectionRepository($this->db)))->seed();
    }

    /** @return list<string> the names of the roles assigned to a user */
    private function roleNames(int $userId): array
    {
        return array_map(static fn ($r): string => $r->name, $this->roles->rolesForUser($userId));
    }

    /**
     * @param array<string,mixed> $arguments
     * @return array<string,mixed>
     */
    private function call(string $name, array $arguments, string $token): array
    {
        $server  = ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_AUTHORIZATION' => 'Bearer ' . $token];
        $body    = ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $name, 'arguments' => $arguments]];
        $request = new Request('POST', '/api/v1/mcp', [], [], $server, [], null, json_encode($body, JSON_THROW_ON_ERROR));
        return json_decode($this->throughKernel($request)->body, true);
    }

    /** @return list<string> */
    private function toolNames(string $token): array
    {
        $server  = ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_AUTHORIZATION' => 'Bearer ' . $token];
        $body    = ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => []];
        $request = new Request('POST', '/api/v1/mcp', [], [], $server, [], null, json_encode($body, JSON_THROW_ON_ERROR));
        return array_column(json_decode($this->throughKernel($request)->body, true)['result']['tools'], 'name');
    }

    /**
     * @param array<string,mixed> $response
     * @return array<string,mixed>
     */
    private function structured(array $response): array
    {
        return $response['result']['structuredContent'];
    }

    // ------------------------------------------------------------------- users

    public function test_user_tools_need_users_write(): void
    {
        $content = $this->tokens->create('C', ['posts:read']);
        $admin   = $this->tokens->create('U', ['users:write']);

        self::assertNotContains('create_user', $this->toolNames($content));
        self::assertSame(-32602, $this->call('create_user', ['email' => 'x@y.z'], $content)['error']['code']);
        self::assertContains('create_user', $this->toolNames($admin));
        self::assertContains('list_roles', $this->toolNames($admin));
    }

    public function test_create_user_assigns_a_real_role_not_the_legacy_column(): void
    {
        $this->seedRoles();
        $token = $this->tokens->create('A', ['admin']); // holds everything → may grant any role

        // Given password: the user gets the editor ROLE (real capabilities), and
        // the legacy column is a neutral placeholder, never the granted role.
        $this->call('create_user', ['email' => 'ed@site.test', 'password' => 's3cretpass', 'role' => 'editor'], $token);
        $ed = $this->users->findByEmail('ed@site.test');
        self::assertNotNull($ed);
        self::assertSame(['editor'], $this->roleNames($ed->id), 'assigned the editor role');
        self::assertContains('*:read', $this->roles->capabilitiesForUser($ed->id), 'the role confers real capabilities');
        self::assertSame('author', $ed->role, 'legacy column stays a least-privilege placeholder');

        // Omitted password: a strong one is generated and returned once, and works.
        $made = $this->structured($this->call('create_user', ['email' => 'au@site.test', 'role' => 'author'], $token));
        self::assertArrayHasKey('generated_password', $made);
        self::assertSame(['author'], $made['user']['roles']);
        $hash = $this->db->selectOne('SELECT password FROM nb_users WHERE email = :e', ['e' => 'au@site.test'])['password'];
        self::assertTrue(Password::verify($made['generated_password'], (string) $hash), 'the generated password logs in');
    }

    public function test_create_user_rejects_bad_input(): void
    {
        $this->seedRoles();
        $token = $this->tokens->create('A', ['admin']);
        $this->call('create_user', ['email' => 'dup@site.test', 'password' => 'goodpass1'], $token);

        self::assertSame('invalid', $this->structured($this->call('create_user', ['email' => 'dup@site.test', 'password' => 'goodpass1'], $token))['error']['code'], 'duplicate email');
        self::assertSame('invalid', $this->structured($this->call('create_user', ['email' => 'bad@site.test', 'role' => 'wizard'], $token))['error']['code'], 'unknown role');
        self::assertSame('invalid', $this->structured($this->call('create_user', ['email' => 'weak@site.test', 'password' => 'short'], $token))['error']['code'], 'weak password');
    }

    public function test_create_user_cannot_grant_authority_the_caller_lacks(): void
    {
        // The escalation guard (the High). A users:write token holds neither admin
        // nor the editor role's capabilities, so it can grant neither.
        $this->seedRoles();
        $token = $this->tokens->create('U', ['users:write']);

        $asAdmin = $this->structured($this->call('create_user', ['email' => 'evil@site.test', 'role' => 'admin', 'password' => 'Known-Pass-1'], $token));
        self::assertSame('forbidden', $asAdmin['error']['code'], 'cannot mint an admin');
        self::assertNull($this->users->findByEmail('evil@site.test'), 'no user created on a rejected escalation');

        $asEditor = $this->structured($this->call('create_user', ['email' => 'ed2@site.test', 'role' => 'editor'], $token));
        self::assertSame('forbidden', $asEditor['error']['code'], 'users:write does not hold *:read/media, so cannot grant editor');
        self::assertNull($this->users->findByEmail('ed2@site.test'));

        // A custom god-role (no literal "admin" string) is blocked on its first
        // un-held management capability.
        $this->roles->create('Super', ['schema:write', 'users:write', 'tokens:write', 'settings:write'], false);
        $asSuper = $this->structured($this->call('create_user', ['email' => 'sup@site.test', 'role' => 'Super'], $token));
        self::assertSame('forbidden', $asSuper['error']['code']);
        self::assertNull($this->users->findByEmail('sup@site.test'));
    }

    public function test_set_role_reassigns_through_roles(): void
    {
        $this->seedRoles();
        $token = $this->tokens->create('A', ['admin']);
        $this->call('create_user', ['email' => 'p@site.test', 'password' => 'goodpass1', 'role' => 'editor'], $token);
        $id = $this->users->findByEmail('p@site.test')->id;

        self::assertFalse($this->call('set_role', ['email' => 'p@site.test', 'role' => 'author'], $token)['result']['isError']);
        self::assertSame(['author'], $this->roleNames($id), 'assignment replaced, via nb_user_roles');
    }

    public function test_set_role_cannot_strip_a_role_the_caller_could_not_grant(): void
    {
        // Both-directions subset-only: a manager who does not hold `admin` cannot
        // demote an admin, even to a role it *can* grant — no sabotage of a superior.
        $this->seedRoles();
        $adminRole = $this->roles->findByName('admin');

        // Two admins, so the last-admin guard is not what blocks us.
        $a1 = $this->users->create('A1', 'a1@site.test', Password::hash('x'), 'author');
        $a2 = $this->users->create('A2', 'a2@site.test', Password::hash('x'), 'author');
        $this->roles->syncUserRoles($a1, [$adminRole->id]);
        $this->roles->syncUserRoles($a2, [$adminRole->id]);

        // Token holds editor's caps (so the *new* role passes) but not admin.
        $token = $this->tokens->create('M', ['users:write', '*:read', 'media:read', 'media:write']);
        $res   = $this->structured($this->call('set_role', ['email' => 'a1@site.test', 'role' => 'editor'], $token));

        self::assertSame('forbidden', $res['error']['code']);
        self::assertSame(['admin'], $this->roleNames($a1), 'the superior keeps the admin role');
    }

    public function test_set_role_never_demotes_the_last_admin_counted_by_role_not_the_legacy_column(): void
    {
        // AUTH-4: an admin whose LEGACY column is 'author' (as the admin UI leaves
        // it) is still counted — the guard reads nb_user_roles, not nb_users.role.
        $this->seedRoles();
        $adminRole = $this->roles->findByName('admin');
        $uiAdmin   = $this->users->create('UI', 'ui-admin@site.test', Password::hash('x'), 'author');
        $this->roles->syncUserRoles($uiAdmin, [$adminRole->id]); // the only admin

        $token   = $this->tokens->create('A', ['admin']);
        $blocked = $this->structured($this->call('set_role', ['email' => 'ui-admin@site.test', 'role' => 'editor'], $token));
        self::assertSame('invalid', $blocked['error']['code'], 'the only (role-held) admin cannot be demoted');
        self::assertSame(['admin'], $this->roleNames($uiAdmin));

        // A second admin frees the demotion.
        $this->roles->syncUserRoles($this->users->create('B', 'b@site.test', Password::hash('x'), 'author'), [$adminRole->id]);
        self::assertFalse($this->call('set_role', ['email' => 'ui-admin@site.test', 'role' => 'editor'], $token)['result']['isError']);
        self::assertSame(['editor'], $this->roleNames($uiAdmin));
    }

    public function test_list_users_reports_assigned_roles(): void
    {
        $this->seedRoles();
        $token = $this->tokens->create('A', ['admin']);
        $this->call('create_user', ['email' => 'r@site.test', 'password' => 'goodpass1', 'role' => 'editor'], $token);

        $rows = $this->structured($this->call('list_users', [], $token))['data'];
        $row  = array_values(array_filter($rows, static fn ($u): bool => $u['email'] === 'r@site.test'))[0];
        self::assertSame(['editor'], $row['roles']);
        self::assertArrayNotHasKey('role', $row, 'no misleading legacy role string');
    }

    public function test_list_roles_lists_assignable_roles(): void
    {
        $this->seedRoles();
        $token = $this->tokens->create('U', ['users:write']);

        $names = array_column($this->structured($this->call('list_roles', [], $token))['data'], 'name');
        self::assertContains('admin', $names);
        self::assertContains('editor', $names);
    }

    public function test_user_tools_require_seeded_roles(): void
    {
        // No seedRoles(): an unseeded install fails closed with a clear message.
        $token = $this->tokens->create('A', ['admin']);
        $res   = $this->structured($this->call('create_user', ['email' => 'x@site.test', 'role' => 'editor'], $token));
        self::assertSame('invalid', $res['error']['code']);
        self::assertStringContainsString('roles:seed', $res['error']['message']);
        self::assertNull($this->users->findByEmail('x@site.test'));
    }

    // ------------------------------------------------------------------ tokens

    public function test_mint_returns_a_working_secret_and_list_never_does(): void
    {
        $token  = $this->tokens->create('T', ['tokens:write', 'posts:read']);
        $minted = $this->structured($this->call('mint_token', ['name' => 'CI bot', 'scopes' => ['posts:read']], $token));

        self::assertArrayHasKey('secret', $minted);
        self::assertNotNull($this->tokens->findByPlaintext($minted['secret']), 'the minted secret authenticates');

        // list_tokens exposes metadata but no secret.
        $list = $this->structured($this->call('list_tokens', [], $token))['data'];
        foreach ($list as $row) {
            self::assertArrayNotHasKey('secret', $row);
        }
    }

    public function test_mint_cannot_grant_scopes_the_minter_does_not_hold(): void
    {
        // Holds tokens:write + posts:write, but not admin/users:write.
        $token = $this->tokens->create('T', ['tokens:write', 'posts:write']);

        self::assertFalse($this->call('mint_token', ['name' => 'ok', 'scopes' => ['posts:write']], $token)['result']['isError'], 'can grant what it holds');
        self::assertSame('forbidden', $this->call('mint_token', ['name' => 'esc', 'scopes' => ['admin']], $token)['result']['structuredContent']['error']['code'], 'cannot escalate to admin');
        self::assertSame('forbidden', $this->call('mint_token', ['name' => 'esc2', 'scopes' => ['users:write']], $token)['result']['structuredContent']['error']['code'], 'cannot grant an unheld capability');
    }

    public function test_admin_can_mint_admin(): void
    {
        $admin  = $this->tokens->create('root', ['admin']);
        $result = $this->call('mint_token', ['name' => 'deputy', 'scopes' => ['admin']], $admin);
        self::assertFalse($result['result']['isError'], 'admin may grant admin');
    }

    // -------------------------------------------------------- role-bound tokens

    public function test_mint_can_bind_a_role_whose_capabilities_the_minter_holds(): void
    {
        $roles = new \Nimbus\Auth\RoleRepository($this->db);
        $roles->create('writer', ['posts:write'], false);

        // Minter holds tokens:write + posts:write, so it may bind `writer`.
        $token  = $this->tokens->create('T', ['tokens:write', 'posts:write']);
        $result = $this->call('mint_token', ['name' => 'bound', 'role' => 'writer'], $token);
        self::assertFalse($result['result']['isError'], 'a role within the minter\'s authority may be bound');

        // The minted token's authority is the role's live capabilities.
        $secret = $result['result']['structuredContent']['secret'];
        $minted = $this->tokens->findByPlaintext($secret);
        self::assertNotNull($minted);
        $principal = $this->tokens->principalFor($minted);
        self::assertTrue($principal->can('posts', 'write'), 'it draws the role\'s caps');
        self::assertTrue($principal->can('posts', 'read'), 'write implies read');
        self::assertFalse($principal->can('users', 'write'), 'and nothing more');
    }

    public function test_mint_cannot_bind_a_role_beyond_the_minter(): void
    {
        $roles = new \Nimbus\Auth\RoleRepository($this->db);
        $roles->create('superuser', ['admin'], false);
        $roles->create('user-admin', ['users:write'], false);

        // Holds tokens:write + posts:write only — neither admin nor users:write.
        $token = $this->tokens->create('T', ['tokens:write', 'posts:write']);

        self::assertSame('forbidden', $this->call('mint_token', ['name' => 'esc', 'role' => 'superuser'], $token)['result']['structuredContent']['error']['code'], 'cannot launder admin through a role');
        self::assertSame('forbidden', $this->call('mint_token', ['name' => 'esc2', 'role' => 'user-admin'], $token)['result']['structuredContent']['error']['code'], 'nor an unheld management capability');
    }

    public function test_binding_an_admin_role_requires_holding_admin(): void
    {
        $roles = new \Nimbus\Auth\RoleRepository($this->db);
        $roles->create('superuser', ['admin'], false);

        $admin  = $this->tokens->create('root', ['admin']);
        self::assertFalse($this->call('mint_token', ['name' => 'deputy', 'role' => 'superuser'], $admin)['result']['isError'], 'an admin may mint an admin-role token');
    }

    public function test_mint_needs_at_least_a_scope_or_a_role(): void
    {
        $token = $this->tokens->create('T', ['tokens:write', 'posts:write']);
        self::assertSame('invalid', $this->call('mint_token', ['name' => 'empty'], $token)['result']['structuredContent']['error']['code'], 'neither scopes nor a role → rejected');
    }

    public function test_mint_rejects_an_unknown_role(): void
    {
        $token = $this->tokens->create('T', ['tokens:write']);
        self::assertSame('invalid', $this->call('mint_token', ['name' => 'ghost', 'role' => 'nope'], $token)['result']['structuredContent']['error']['code']);
    }

    public function test_list_tokens_shows_the_bound_role(): void
    {
        $roles = new \Nimbus\Auth\RoleRepository($this->db);
        $roles->create('writer', ['posts:write'], false);

        $token = $this->tokens->create('T', ['tokens:write', 'posts:write']);
        $this->call('mint_token', ['name' => 'bound', 'role' => 'writer'], $token);

        $row = null;
        foreach ($this->structured($this->call('list_tokens', [], $token))['data'] as $r) {
            if ($r['name'] === 'bound') {
                $row = $r;
            }
        }
        self::assertNotNull($row);
        self::assertSame('writer', $row['role'], 'the listing names the bound role');
    }

    public function test_revoke_token_disables_it(): void
    {
        $token  = $this->tokens->create('T', ['tokens:write', 'posts:read']);
        $secret = $this->structured($this->call('mint_token', ['name' => 'doomed', 'scopes' => ['posts:read']], $token))['secret'];

        // Find its id from the list, then revoke.
        $id = 0;
        foreach ($this->structured($this->call('list_tokens', [], $token))['data'] as $row) {
            if ($row['name'] === 'doomed') {
                $id = $row['id'];
            }
        }
        self::assertGreaterThan(0, $id);
        self::assertFalse($this->call('revoke_token', ['id' => $id], $token)['result']['isError']);
        self::assertNull($this->tokens->findByPlaintext($secret), 'a revoked token no longer authenticates');
    }
}

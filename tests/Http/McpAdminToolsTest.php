<?php

declare(strict_types=1);

namespace Nimbus\Tests\Http;

use Nimbus\Api\ApiTokenRepository;
use Nimbus\Auth\Password;
use Nimbus\Auth\UserRepository;
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

    protected function setUp(): void
    {
        parent::setUp();
        $this->tokens = new ApiTokenRepository($this->db);
        $this->users  = new UserRepository($this->db);
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
    }

    public function test_create_user_with_and_without_a_password(): void
    {
        $token = $this->tokens->create('U', ['users:write']);

        // Given password: stored hashed.
        $this->call('create_user', ['email' => 'ed@site.test', 'password' => 's3cretpass', 'role' => 'editor'], $token);
        $ed = $this->users->findByEmail('ed@site.test');
        self::assertNotNull($ed);
        self::assertSame('editor', $ed->role);

        // Omitted password: a strong one is generated and returned once, and works.
        $made = $this->structured($this->call('create_user', ['email' => 'au@site.test', 'role' => 'author'], $token));
        self::assertArrayHasKey('generated_password', $made);
        $hash = $this->db->selectOne('SELECT password FROM nb_users WHERE email = :e', ['e' => 'au@site.test'])['password'];
        self::assertTrue(Password::verify($made['generated_password'], (string) $hash), 'the generated password logs in');
    }

    public function test_create_user_rejects_bad_input(): void
    {
        $token = $this->tokens->create('U', ['users:write']);
        $this->call('create_user', ['email' => 'dup@site.test', 'password' => 'goodpass1'], $token);

        self::assertSame('invalid', $this->call('create_user', ['email' => 'dup@site.test', 'password' => 'goodpass1'], $token)['result']['structuredContent']['error']['code'], 'duplicate email');
        self::assertSame('invalid', $this->call('create_user', ['email' => 'bad@site.test', 'role' => 'wizard'], $token)['result']['structuredContent']['error']['code'], 'unknown role');
        self::assertSame('invalid', $this->call('create_user', ['email' => 'weak@site.test', 'password' => 'short'], $token)['result']['structuredContent']['error']['code'], 'weak password');
    }

    public function test_set_role_but_never_the_last_admin(): void
    {
        $token = $this->tokens->create('U', ['users:write']);
        $this->call('create_user', ['email' => 'boss@site.test', 'password' => 'goodpass1', 'role' => 'admin'], $token);

        // Only admin → cannot be demoted.
        $blocked = $this->call('set_role', ['email' => 'boss@site.test', 'role' => 'editor'], $token);
        self::assertSame('invalid', $blocked['result']['structuredContent']['error']['code']);
        self::assertSame('admin', $this->users->findByEmail('boss@site.test')?->role);

        // With a second admin, the demotion is allowed.
        $this->call('create_user', ['email' => 'boss2@site.test', 'password' => 'goodpass1', 'role' => 'admin'], $token);
        self::assertFalse($this->call('set_role', ['email' => 'boss@site.test', 'role' => 'editor'], $token)['result']['isError']);
        self::assertSame('editor', $this->users->findByEmail('boss@site.test')?->role);
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

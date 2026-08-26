<?php

declare(strict_types=1);

namespace Nimbus\Tests\Http;

use Nimbus\Api\ApiTokenRepository;
use Nimbus\Auth\Password;
use Nimbus\Http\FormNonce;
use Nimbus\Http\Request;

/**
 * Demo mode (`NIMBUS_DEMO`) — the public, shared, hourly-reset sandbox. The
 * admin shows a persistent banner and change-password is disabled: hidden in the
 * UI AND refused at the handler, so a direct POST can't lock other visitors out
 * between resets. Off by default (the marketing site and real installs are
 * unaffected — covered by the change-password suite running without the flag).
 */
final class DemoModeTest extends HttpTestCase
{
    protected function tearDown(): void
    {
        putenv('NIMBUS_DEMO'); // unset so the flag never leaks into other tests
        parent::tearDown();
    }

    private function enableDemo(): void
    {
        putenv('NIMBUS_DEMO=1');
        $this->rebuildRouter(); // controllers re-read Config::demo() at build time
    }

    public function test_demo_banner_shows_and_change_password_form_is_hidden(): void
    {
        $this->enableDemo();
        $this->actingAs('admin');
        $body = $this->get('/admin/settings')->body;

        self::assertStringContainsString('Live demo', $body, 'the demo banner renders in the admin shell');
        self::assertStringNotContainsString('action="/admin/settings/password"', $body, 'the change-password form is hidden in demo mode');
    }

    public function test_the_banner_is_absent_by_default(): void
    {
        $this->actingAs('admin');
        $body = $this->get('/admin/settings')->body;
        self::assertStringNotContainsString('Live demo', $body);
        self::assertStringContainsString('action="/admin/settings/password"', $body, 'the form is present when not in demo mode');
    }

    public function test_a_direct_change_password_post_is_refused_in_demo_mode(): void
    {
        $this->enableDemo();
        $id = $this->actingAs('admin');
        $before = $this->db->selectOne('SELECT password FROM nb_users WHERE id = :id', ['id' => $id]);
        self::assertNotNull($before);

        $resp = $this->post('/admin/settings/password', [
            'current_password' => 'correct-horse',
            'new_password'     => 'a-brand-new-passphrase',
            'confirm_password' => 'a-brand-new-passphrase',
        ]);

        self::assertStringContainsString('disabled in the live demo', $resp->body);
        $after = $this->db->selectOne('SELECT password FROM nb_users WHERE id = :id', ['id' => $id]);
        self::assertNotNull($after);
        self::assertSame($before['password'], $after['password'], 'the password must not change in demo mode');
        self::assertTrue(Password::verify('correct-horse', (string) $after['password']));
    }

    private function tokenCount(): int
    {
        $row = $this->db->selectOne('SELECT COUNT(*) AS c FROM nb_api_tokens');
        return (int) ($row['c'] ?? 0);
    }

    public function test_admin_token_minting_is_refused_in_demo(): void
    {
        $this->enableDemo();
        $this->actingAs('admin');
        $before = $this->tokenCount();

        $resp = $this->post('/admin/tokens', ['name' => 'Grief', 'scope_all' => '1', '_nonce' => FormNonce::issue()]);

        $this->assertRedirectsTo($resp, '/admin/tokens?err=demo-disabled');
        self::assertSame($before, $this->tokenCount(), 'no token is minted via the admin UI in demo mode');
    }

    public function test_mcp_token_minting_is_refused_in_demo(): void
    {
        $this->enableDemo();
        // A pre-existing admin token authenticates the MCP call (created directly,
        // bypassing the guarded mint path); the guard must still refuse mint_token.
        $auth   = (new ApiTokenRepository($this->db))->create('auth', ['admin']);
        $before = $this->tokenCount();

        $server = ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_AUTHORIZATION' => 'Bearer ' . $auth];
        $body   = ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'mint_token', 'arguments' => ['name' => 'grief', 'scopes' => ['posts:read']]]];
        $req    = new Request('POST', '/api/v1/mcp', [], [], $server, [], null, json_encode($body, JSON_THROW_ON_ERROR));
        $resp   = json_decode($this->throughKernel($req)->body, true);

        self::assertTrue($resp['result']['isError']);
        self::assertSame('forbidden', $resp['result']['structuredContent']['error']['code']);
        self::assertSame($before, $this->tokenCount(), 'no token is minted over MCP in demo mode');
    }
}

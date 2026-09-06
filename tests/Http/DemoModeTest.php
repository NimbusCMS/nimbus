<?php

declare(strict_types=1);

namespace Nimbus\Tests\Http;

use Nimbus\Api\ApiTokenRepository;
use Nimbus\Auth\Password;
use Nimbus\Http\FormNonce;
use Nimbus\Http\Request;
use Nimbus\Support\Config;

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
        putenv('NIMBUS_DEMO_EMAIL');
        putenv('NIMBUS_DEMO_PASSWORD');
        $demoFile = Config::basePath() . '/config/demo.php';
        if (is_file($demoFile)) {
            @unlink($demoFile); // remove any config/demo.php a test wrote
        }
        parent::tearDown();
    }

    /**
     * Write a temporary config/demo.php with the given accounts (removed in tearDown).
     *
     * @param list<array{label:string,email:string,password:string}> $accounts
     */
    private function writeDemoAccounts(array $accounts): void
    {
        $export = var_export(['accounts' => $accounts], true);
        file_put_contents(Config::basePath() . '/config/demo.php', "<?php\n\nreturn {$export};\n");
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

    public function test_login_prefills_the_published_demo_credentials(): void
    {
        putenv('NIMBUS_DEMO=1');
        putenv('NIMBUS_DEMO_EMAIL=demo@example.test');
        putenv('NIMBUS_DEMO_PASSWORD=explore-nimbus-demo');
        $this->rebuildRouter();

        $body = $this->get('/admin/login')->body;
        self::assertStringContainsString('value="demo@example.test"', $body, 'email is pre-filled');
        self::assertStringContainsString('value="explore-nimbus-demo"', $body, 'password is pre-filled');
        self::assertStringContainsString('the credentials are filled in', $body, 'the one-click note shows');
    }

    public function test_login_never_prefills_outside_demo(): void
    {
        $body = $this->get('/admin/login')->body;
        self::assertStringNotContainsString('the credentials are filled in', $body);
        self::assertStringNotContainsString('value="explore-nimbus-demo"', $body);
    }

    public function test_login_shows_a_role_picker_with_multiple_demo_accounts(): void
    {
        $this->writeDemoAccounts([
            ['label' => 'Manager', 'email' => 'manager@x.demo', 'password' => 'the-demo-pass'],
            ['label' => 'Cook', 'email' => 'cook@x.demo', 'password' => 'the-demo-pass'],
        ]);
        putenv('NIMBUS_DEMO=1');
        $this->rebuildRouter();

        $body = $this->get('/admin/login')->body;
        self::assertStringContainsString('id="demo-as"', $body, 'the role picker renders');
        self::assertStringContainsString('>Manager</option>', $body);
        self::assertStringContainsString('>Cook</option>', $body);
        // First account backs the server-side pre-fill (works with JS off).
        self::assertStringContainsString('value="manager@x.demo"', $body, 'the first account pre-fills the email');
        // The fill script carries the page nonce and both accounts.
        self::assertMatchesRegularExpression('/<script nonce="[^"]+">/', $body, 'the fill script is nonce\'d');
        self::assertStringContainsString('cook@x.demo', $body, 'the second account is available to the picker script');
    }

    public function test_no_role_picker_when_demo_is_off_even_with_a_config_file(): void
    {
        // The config file exists, but without NIMBUS_DEMO there must be no picker,
        // no pre-fill, no demo affordance at all — a real install is untouched.
        $this->writeDemoAccounts([
            ['label' => 'Manager', 'email' => 'manager@x.demo', 'password' => 'the-demo-pass'],
            ['label' => 'Cook', 'email' => 'cook@x.demo', 'password' => 'the-demo-pass'],
        ]);
        $this->rebuildRouter();

        $body = $this->get('/admin/login')->body;
        self::assertStringNotContainsString('id="demo-as"', $body, 'no picker outside demo mode');
        self::assertStringNotContainsString('manager@x.demo', $body, 'no account leaks outside demo mode');
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

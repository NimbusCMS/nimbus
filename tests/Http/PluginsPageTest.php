<?php

declare(strict_types=1);

namespace Nimbus\Tests\Http;

use Nimbus\Admin\AdminController;
use Nimbus\Http\Request;
use Nimbus\Http\Response;
use Nimbus\Http\Router;
use Nimbus\Plugin\PluginStatus;

/**
 * The read-only Plugins admin page.
 *
 * The statuses are computed by the kernel at boot and handed to the controller,
 * so these tests build a controller with a known list rather than installing
 * real packages — the loader's own behaviour is covered by PluginLoaderTest.
 * What matters here is access control, escaping, and that the page reflects
 * what it was given without offering any action.
 */
final class PluginsPageTest extends HttpTestCase
{
    /** @param list<PluginStatus> $statuses */
    private function router(array $statuses): Router
    {
        $router = new Router();
        (new AdminController($this->db, $this->auth, $statuses))->routes($router);
        return $router;
    }

    /** @param list<PluginStatus> $statuses */
    private function fetch(array $statuses = []): Response
    {
        $request = new Request('GET', '/admin/plugins', [], [], ['REMOTE_ADDR' => '127.0.0.1'], []);
        try {
            $response = $this->router($statuses)->dispatch($request);
        } catch (\Nimbus\Http\HttpException $e) {
            // The kernel turns guard short-circuits into their response; mirror it.
            return $e->response;
        }
        self::assertNotNull($response);
        return $response;
    }

    private function healthy(): PluginStatus
    {
        return new PluginStatus('nimbuscms.markdown', 'nimbuscms/markdown', 'Markdown', '0.1.0', true, PluginStatus::HEALTHY, '', true);
    }

    // -------------------------------------------------------- access control

    public function test_anonymous_request_is_redirected_to_login(): void
    {
        // Middleware gates the whole /admin group; no user acting.
        $this->assertRedirects($this->fetch([]), '/admin/login');
    }

    public function test_a_non_admin_is_turned_away(): void
    {
        $this->actingAs('editor', 'editor@test.local');

        $this->assertRedirects($this->fetch([$this->healthy()]), '/admin');
    }

    public function test_an_admin_sees_the_page(): void
    {
        $this->actingAs('admin');

        $response = $this->fetch([$this->healthy()]);

        self::assertSame(200, $response->status);
        self::assertStringContainsString('Markdown', $response->body);
        self::assertStringContainsString('nimbuscms/markdown', $response->body);
        self::assertStringContainsString('Enabled', $response->body);
        // The badge states the neutral namespace fact, not a trust claim (PLUG-13).
        self::assertStringContainsString('nimbuscms namespace', $response->body);
    }

    // ------------------------------------------------------------- contents

    public function test_empty_state_when_no_plugins_are_installed(): void
    {
        $this->actingAs('admin');

        $response = $this->fetch([]);

        self::assertSame(200, $response->status);
        self::assertStringContainsString('No plugins installed', $response->body);
    }

    public function test_failed_and_disabled_states_render(): void
    {
        $this->actingAs('admin');

        $statuses = [
            new PluginStatus('a.off', 'vendor/off', 'Off', '1.0.0', false, PluginStatus::DISABLED, 'Disabled in configuration.', false),
            new PluginStatus('a.bad', 'vendor/bad', 'Bad', '1.0.0', true, PluginStatus::FAILED, 'Field type "text" is already provided by core.', false),
        ];
        $response = $this->fetch($statuses);

        self::assertStringContainsString('Disabled', $response->body);
        self::assertStringContainsString('Failed', $response->body);
        self::assertStringContainsString('already provided by core', $response->body);
        self::assertStringContainsString('need', $response->body, 'a problem banner is shown');
    }

    public function test_the_page_offers_no_actions(): void
    {
        $adminId = $this->actingAs('admin');

        $plugins = $this->fetch([$this->healthy()])->body;

        // The admin shell carries one form (logout). A diagnostic page adds
        // none of its own — so its form and button counts match a page that is
        // purely informational. Compared against the dashboard for that baseline.
        $router    = new Router();
        (new AdminController($this->db, $this->auth, []))->routes($router);
        $dashboardResponse = $router->dispatch(
            new Request('GET', '/admin', [], [], ['REMOTE_ADDR' => '127.0.0.1'], []),
        );
        self::assertNotNull($dashboardResponse);
        $dashboard = $dashboardResponse->body;

        self::assertSame(substr_count($dashboard, '<form'), substr_count($plugins, '<form'), 'no extra forms');
        self::assertSame(substr_count($dashboard, '<button'), substr_count($plugins, '<button'), 'no extra buttons');
    }

    public function test_diagnostic_messages_are_html_escaped(): void
    {
        $this->actingAs('admin');

        $statuses = [
            new PluginStatus('x.evil', 'vendor/evil', '<script>alert(1)</script>', '1.0.0', true, PluginStatus::FAILED, '<img src=x onerror=alert(1)>', false),
        ];
        $response = $this->fetch($statuses);

        self::assertStringNotContainsString('<script>alert(1)</script>', $response->body);
        self::assertStringNotContainsString('<img src=x', $response->body);
        self::assertStringContainsString('&lt;script&gt;', $response->body);
    }
}

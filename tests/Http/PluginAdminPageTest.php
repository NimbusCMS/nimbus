<?php

declare(strict_types=1);

namespace Nimbus\Tests\Http;

use Nimbus\Admin\AdminController;
use Nimbus\Admin\AdminPageRegistry;
use Nimbus\Admin\PluginPagesController;
use Nimbus\Http\Response;
use Nimbus\Http\Router;

/**
 * A plugin's admin page (capability D): login-gated, rendered in the admin shell,
 * and shown in the sidebar of every admin page.
 */
final class PluginAdminPageTest extends HttpTestCase
{
    private function registry(callable $handler): AdminPageRegistry
    {
        $registry = new AdminPageRegistry();
        $registry->add('analytics', 'Analytics', '📊', $handler, 'nimbuscms.analytics');
        return $registry;
    }

    private function pluginRouter(AdminPageRegistry $registry): Router
    {
        $router = new Router();
        (new PluginPagesController($this->db, $this->auth, $registry))->routes($router);
        return $router;
    }

    private function registryWithCapability(callable $handler, string $capability): AdminPageRegistry
    {
        $registry = new AdminPageRegistry();
        $registry->add('reports', 'Reports', '📊', $handler, 'nimbuscms.reports', $capability);
        return $registry;
    }

    /** Dispatch, resolving a guard short-circuit to its Response as the kernel does. */
    private function dispatchGuarded(Router $router, \Nimbus\Http\Request $req): Response
    {
        try {
            $response = $router->dispatch($req);
            self::assertNotNull($response);
            /** @var Response $response */
            return $response;
        } catch (\Nimbus\Http\HttpException $e) {
            return $e->response;
        }
    }

    public function test_a_plugin_admin_page_requires_login(): void
    {
        $router   = $this->pluginRouter($this->registry(static fn (): string => '<h1>Charts</h1>'));
        $response = $router->dispatch($this->request('GET', '/admin/analytics'));

        self::assertNotNull($response);
        /** @var Response $response */
        self::assertSame(302, $response->status);
        self::assertSame('/admin/login', $response->header('Location'));
    }

    public function test_an_authenticated_page_renders_in_the_admin_shell(): void
    {
        $this->actingAs('admin');
        $router   = $this->pluginRouter($this->registry(static fn (): string => '<h1>Charts</h1>'));
        $response = $router->dispatch($this->request('GET', '/admin/analytics'));

        self::assertNotNull($response);
        /** @var Response $response */
        self::assertSame(200, $response->status);
        self::assertStringContainsString('<h1>Charts</h1>', $response->body, 'the handler content');
        self::assertStringContainsString('href="/admin/analytics"', $response->body, 'its own sidebar entry');
        self::assertStringContainsString('nb-side', $response->body, 'inside the admin shell');
    }

    public function test_a_handler_may_return_a_full_response(): void
    {
        $this->actingAs('admin');
        $router   = $this->pluginRouter($this->registry(static fn (): Response => Response::redirect('/admin')));
        $response = $router->dispatch($this->request('GET', '/admin/analytics'));

        self::assertNotNull($response);
        /** @var Response $response */
        self::assertSame(302, $response->status);
        self::assertSame('/admin', $response->header('Location'));
    }

    public function test_the_plugin_page_appears_in_a_core_admin_page_nav(): void
    {
        $this->actingAs('admin');
        $router = new Router();
        (new AdminController($this->db, $this->auth, [], $this->registry(static fn (): string => 'x')))->routes($router);

        $response = $router->dispatch($this->request('GET', '/admin'));

        self::assertNotNull($response);
        /** @var Response $response */
        self::assertStringContainsString('href="/admin/analytics"', $response->body, 'the plugin link shows on a core page');
    }

    // ------------------------------------------------ capability-gated pages

    public function test_a_capability_gated_page_denies_a_non_holder_at_the_route(): void
    {
        $this->actingWithCapabilities(['posts:write']); // not users:write, not admin
        $router   = $this->pluginRouter($this->registryWithCapability(static fn (): string => 'secret', 'users:write'));
        $response = $this->dispatchGuarded($router, $this->request('GET', '/admin/reports'));

        self::assertSame(302, $response->status);
        self::assertSame('/admin', $response->header('Location'), 'the route gate (not just the nav) turns a non-holder away');
    }

    public function test_a_capability_gated_page_allows_a_holder(): void
    {
        $this->actingWithCapabilities(['users:write']);
        $router   = $this->pluginRouter($this->registryWithCapability(static fn (): string => '<h1>Reports</h1>', 'users:write'));
        $response = $router->dispatch($this->request('GET', '/admin/reports'));

        self::assertNotNull($response);
        /** @var Response $response */
        self::assertSame(200, $response->status);
        self::assertStringContainsString('<h1>Reports</h1>', $response->body);
    }

    public function test_a_wildcard_read_holder_cannot_reach_a_management_gated_page(): void
    {
        // `*:read` (an editor/author grant) must NOT satisfy a management capability
        // — the gate is wildcard-immune because the declared cap is management.
        $this->actingWithCapabilities(['*:read']);
        $router   = $this->pluginRouter($this->registryWithCapability(static fn (): string => 'secret', 'media:read'));
        $response = $this->dispatchGuarded($router, $this->request('GET', '/admin/reports'));

        self::assertSame(302, $response->status, 'a content wildcard does not open a management-gated page');
    }

    public function test_a_no_capability_page_stays_login_only(): void
    {
        // BC: any signed-in user reaches an uncapped page (no accidental deny-all).
        $this->actingWithCapabilities(['posts:read']);
        $router   = $this->pluginRouter($this->registry(static fn (): string => '<h1>Charts</h1>'));
        $response = $router->dispatch($this->request('GET', '/admin/analytics'));

        self::assertNotNull($response);
        /** @var Response $response */
        self::assertSame(200, $response->status, 'no capability = login-only, unchanged');
    }

    public function test_the_nav_hides_a_capability_page_from_a_non_holder(): void
    {
        $this->actingWithCapabilities(['posts:write']);
        $router = new Router();
        (new AdminController($this->db, $this->auth, [], $this->registryWithCapability(static fn (): string => 'x', 'users:write')))->routes($router);

        $response = $router->dispatch($this->request('GET', '/admin'));

        self::assertNotNull($response);
        /** @var Response $response */
        self::assertStringNotContainsString('href="/admin/reports"', $response->body, 'no dead link for a non-holder');
    }
}

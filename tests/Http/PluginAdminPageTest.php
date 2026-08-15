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
}

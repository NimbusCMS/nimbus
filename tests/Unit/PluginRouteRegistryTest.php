<?php

declare(strict_types=1);

namespace Nimbus\Tests\Unit;

use Nimbus\Http\PluginRouteRegistry;
use Nimbus\Http\Request;
use Nimbus\Http\Response;
use Nimbus\Http\Router;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Plugin public routes (ADR 0017, H4). The containment is the reserved `/ext/`
 * prefix and the per-plugin-unique namespace, both enforced here at registration.
 */
final class PluginRouteRegistryTest extends TestCase
{
    private function handler(): callable
    {
        return static fn (Request $r, array $p): Response => Response::html('ok');
    }

    public function test_a_route_is_mounted_under_the_ext_prefix_and_namespace(): void
    {
        $reg = new PluginRouteRegistry();
        $reg->add('nimbuscms.shop', 'shop', 'POST', '/webhook', $this->handler());

        $routes = $reg->all();
        self::assertCount(1, $routes);
        self::assertSame('POST', $routes[0]['method']);
        self::assertSame('/ext/shop/webhook', $routes[0]['pattern']);
    }

    public function test_a_root_path_serves_the_namespace_itself(): void
    {
        $reg = new PluginRouteRegistry();
        $reg->add('nimbuscms.shop', 'shop', 'GET', '/', $this->handler());
        self::assertSame('/ext/shop', $reg->all()[0]['pattern']);
    }

    public function test_the_prefix_gives_every_plugin_route_at_least_three_segments(): void
    {
        // The structural guarantee: /ext/{ns}/... can never be a 1- or 2-segment
        // content path, so it cannot collide with the site catch-alls. Even the
        // namespace root is /ext/{ns} — 2 segments under a reserved first segment.
        $reg = new PluginRouteRegistry();
        $reg->add('nimbuscms.shop', 'shop', 'GET', '/cart', $this->handler());
        self::assertStringStartsWith('/' . PluginRouteRegistry::PREFIX . '/', $reg->all()[0]['pattern']);
    }

    public function test_a_plugin_may_register_several_routes_in_its_namespace(): void
    {
        $reg = new PluginRouteRegistry();
        $reg->add('nimbuscms.shop', 'shop', 'GET', '/cart', $this->handler());
        $reg->add('nimbuscms.shop', 'shop', 'POST', '/checkout', $this->handler());
        self::assertCount(2, $reg->all());
    }

    public function test_two_plugins_cannot_share_a_namespace(): void
    {
        $reg = new PluginRouteRegistry();
        $reg->add('nimbuscms.shop', 'shop', 'GET', '/cart', $this->handler());

        $this->expectException(\InvalidArgumentException::class);
        $reg->add('acme.other', 'shop', 'GET', '/x', $this->handler());
    }

    public function test_forget_provider_removes_its_routes_and_frees_the_namespace(): void
    {
        $reg = new PluginRouteRegistry();
        $reg->add('nimbuscms.shop', 'shop', 'GET', '/cart', $this->handler());
        $reg->forgetProvider('nimbuscms.shop');

        self::assertSame([], $reg->all());
        // The namespace is free again — another plugin may now claim it.
        $reg->add('acme.other', 'shop', 'GET', '/x', $this->handler());
        self::assertCount(1, $reg->all());
    }

    #[DataProvider('badNamespaces')]
    public function test_a_malformed_namespace_is_refused(string $namespace): void
    {
        $reg = new PluginRouteRegistry();
        $this->expectException(\InvalidArgumentException::class);
        $reg->add('nimbuscms.p', $namespace, 'GET', '/x', $this->handler());
    }

    /** @return array<string,array{string}> */
    public static function badNamespaces(): array
    {
        return [
            'empty'         => [''],
            'uppercase'     => ['Shop'],
            'leading digit' => ['1shop'],
            'slash'         => ['sh/op'],
            'dot'           => ['sh.op'],
        ];
    }

    public function test_an_unsupported_method_is_refused(): void
    {
        $reg = new PluginRouteRegistry();
        $this->expectException(\InvalidArgumentException::class);
        $reg->add('nimbuscms.shop', 'shop', 'TRACE', '/x', $this->handler());
    }

    public function test_a_path_that_escapes_the_prefix_is_refused(): void
    {
        $reg = new PluginRouteRegistry();
        $this->expectException(\InvalidArgumentException::class);
        $reg->add('nimbuscms.shop', 'shop', 'GET', '/../admin', $this->handler());
    }

    public function test_a_mounted_route_is_served_and_never_shadows_content(): void
    {
        // Mirrors the kernel: plugin routes mounted, then the content catch-all
        // last. Proves the plugin route resolves and a content path still routes.
        $reg = new PluginRouteRegistry();
        $reg->add('nimbuscms.shop', 'shop', 'POST', '/webhook', static fn (Request $r, array $p): Response => Response::html('handled'));

        $router = new Router();
        foreach ($reg->all() as $route) {
            $router->post($route['pattern'], $route['handler']);
        }
        $router->get('/{collection}', static fn (Request $r, array $p): Response => Response::html('content:' . $p['collection']));
        $router->get('/{collection}/{slug}', static fn (Request $r, array $p): Response => Response::html('entry'));

        $served = $router->dispatch($this->request('POST', '/ext/shop/webhook'));
        self::assertNotNull($served);
        self::assertSame('handled', $served->body, 'the plugin route is served');

        $content = $router->dispatch($this->request('GET', '/posts'));
        self::assertNotNull($content);
        self::assertSame('content:posts', $content->body, 'a content path is untouched by the plugin route');

        // The webhook is POST-only: a GET to it is a 405, not a fall-through to content.
        $wrongMethod = $router->dispatch($this->request('GET', '/ext/shop/webhook'));
        self::assertNotNull($wrongMethod);
        self::assertSame(405, $wrongMethod->status);
    }

    private function request(string $method, string $path): Request
    {
        return new Request($method, $path, [], [], [], []);
    }
}

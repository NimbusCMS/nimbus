<?php

declare(strict_types=1);

namespace Nimbus\Tests\Unit;

use Nimbus\Http\Request;
use Nimbus\Http\Response;
use Nimbus\Http\Router;
use PHPUnit\Framework\TestCase;

final class RouterTest extends TestCase
{
    private function request(string $method, string $path): Request
    {
        return new Request($method, $path, [], [], [], []);
    }

    public function test_static_and_param_routes_dispatch(): void
    {
        $router = new Router();
        $router->get('/admin', fn (): Response => Response::html('dash'));
        $router->get('/admin/collections/{handle}/entries/{id}/edit', fn (array $p): Response => Response::html("{$p['handle']}:{$p['id']}"));

        self::assertSame('dash', $router->dispatch($this->request('GET', '/admin'))->body);
        self::assertSame('posts:9', $router->dispatch($this->request('GET', '/admin/collections/posts/entries/9/edit'))->body);
    }

    public function test_no_match_returns_null(): void
    {
        $router = new Router();
        $router->get('/admin', fn (): Response => Response::html('x'));

        self::assertNull($router->dispatch($this->request('GET', '/nope')));
        self::assertNull($router->dispatch($this->request('POST', '/admin'))); // wrong method
    }

    public function test_named_route_url_generation(): void
    {
        $router = new Router();
        $router->get('/admin/collections/{handle}/entries/{id}/edit', fn (): Response => Response::html('x'))->name('entries.edit');

        self::assertSame('/admin/collections/posts/entries/9/edit', $router->url('entries.edit', ['handle' => 'posts', 'id' => 9]));
        // extra params become a query string
        self::assertSame('/admin/collections/posts/entries/9/edit?msg=saved', $router->url('entries.edit', ['handle' => 'posts', 'id' => 9, 'msg' => 'saved']));
    }

    public function test_unknown_route_name_throws(): void
    {
        $this->expectException(\RuntimeException::class);
        (new Router())->url('does.not.exist');
    }

    public function test_middleware_short_circuits_before_handler(): void
    {
        $router = new Router();
        $reached = false;
        $router->get('/admin', function () use (&$reached): Response {
            $reached = true;
            return Response::html('handler');
        })->middleware(fn (Request $r): Response => Response::redirect('/login'));

        $response = $router->dispatch($this->request('GET', '/admin'));
        self::assertSame(302, $response->status);
        self::assertSame('/login', $response->headers['Location']);
        self::assertFalse($reached, 'handler must not run when middleware short-circuits');
    }

    public function test_group_applies_prefix_and_middleware(): void
    {
        $router = new Router();
        $calls  = [];
        $mw = function (Request $r) use (&$calls): ?Response {
            $calls[] = 'mw';
            return null; // pass through
        };
        $router->group('/admin', [$mw], function (Router $g): void {
            $g->get('/collections', fn (): Response => Response::html('list'))->name('collections');
        });

        self::assertSame('list', $router->dispatch($this->request('GET', '/admin/collections'))->body);
        self::assertSame(['mw'], $calls, 'group middleware ran');
        self::assertSame('/admin/collections', $router->url('collections'));
    }
}

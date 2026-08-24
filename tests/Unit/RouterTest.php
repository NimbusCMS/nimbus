<?php

declare(strict_types=1);

namespace Nimbus\Tests\Unit;

use Nimbus\Http\Request;
use Nimbus\Http\Response;
use Nimbus\Http\Route;
use Nimbus\Http\Router;
use PHPUnit\Framework\TestCase;

final class RouterTest extends TestCase
{
    private function request(string $method, string $path): Request
    {
        return new Request($method, $path, [], [], [], []);
    }

    public function test_route_match_is_stable_across_repeated_calls(): void
    {
        // HTTP-5: regex() is memoized on the Route, so match() must be
        // byte-identical on every call — a transparent optimization, not a
        // behavior change.
        $handler = static fn (Request $r, array $p): Response => Response::html('ok');

        $route = new Route('GET', '/posts/{slug}', $handler);
        $first = $route->match('/posts/hello');
        self::assertSame(['slug' => 'hello'], $first);
        self::assertSame($first, $route->match('/posts/hello'), 'the memoized regex changed the match result');
        self::assertNull($route->match('/posts/a/b'), 'a single-segment param still rejects a multi-segment path after memoization');

        // The {path*} catch-all still spans slashes after memoization.
        $wild = new Route('GET', '/theme/assets/{path*}', $handler);
        self::assertSame(['path' => 'css/app.css'], $wild->match('/theme/assets/css/app.css'));
        self::assertSame($wild->match('/theme/assets/css/app.css'), $wild->match('/theme/assets/css/app.css'));
    }

    public function test_static_and_param_routes_dispatch(): void
    {
        $router = new Router();
        $router->get('/admin', fn (Request $req, array $p): Response => Response::html('dash'));
        $router->get('/admin/collections/{handle}/entries/{id}/edit', fn (Request $req, array $p): Response => Response::html("{$p['handle']}:{$p['id']}"));

        self::assertSame('dash', $router->dispatch($this->request('GET', '/admin'))->body);
        self::assertSame('posts:9', $router->dispatch($this->request('GET', '/admin/collections/posts/entries/9/edit'))->body);
    }

    public function test_a_wildcard_param_captures_the_rest_of_the_path(): void
    {
        $router = new Router();
        $router->get('/theme/assets/{path*}', fn (Request $req, array $p): Response => Response::html($p['path']));

        // A single segment and a nested path both match; the slashes are kept.
        self::assertSame('app.css', $router->dispatch($this->request('GET', '/theme/assets/app.css'))->body);
        self::assertSame('img/logo.png', $router->dispatch($this->request('GET', '/theme/assets/img/logo.png'))->body);
        // But a plain {param} still stops at one segment.
        self::assertNull($router->dispatch($this->request('GET', '/theme')));
    }

    public function test_a_wildcard_route_generates_a_url_keeping_slashes(): void
    {
        $router = new Router();
        $router->get('/theme/assets/{path*}', fn (Request $req, array $p): Response => Response::html('x'))->name('asset');

        self::assertSame('/theme/assets/img/logo.png', $router->url('asset', ['path' => 'img/logo.png']));
    }

    public function test_handler_receives_the_dispatched_request_instance(): void
    {
        $router   = new Router();
        $received = null;
        $router->get('/admin/{id}', function (Request $req, array $p) use (&$received): Response {
            $received = $req;
            return Response::html((string) $p['id']);
        });

        $request  = $this->request('GET', '/admin/7');
        $response = $router->dispatch($request);

        self::assertSame($request, $received, 'handler must get the kernel Request, not a re-read of globals');
        self::assertSame('7', $response->body);
    }

    public function test_middleware_and_handler_share_one_request(): void
    {
        $router = new Router();
        $seen   = [];
        $router->group('/admin', [function (Request $r) use (&$seen): ?Response {
            $seen[] = $r;
            return null;
        }], function (Router $g) use (&$seen): void {
            $g->get('/collections', function (Request $r, array $p) use (&$seen): Response {
                $seen[] = $r;
                return Response::html('ok');
            });
        });

        $request = $this->request('GET', '/admin/collections');
        $router->dispatch($request);

        self::assertCount(2, $seen);
        self::assertSame($request, $seen[0]);
        self::assertSame($seen[0], $seen[1], 'middleware and handler operate on one request');
    }

    public function test_an_unmatched_path_returns_null(): void
    {
        $router = new Router();
        $router->get('/admin', fn (Request $req, array $p): Response => Response::html('x'));

        self::assertNull($router->dispatch($this->request('GET', '/nope')), 'no route at this path → 404');
    }

    public function test_a_matched_path_with_the_wrong_method_is_a_405_with_allow(): void
    {
        $router = new Router();
        $router->get('/admin', fn (Request $req, array $p): Response => Response::html('x'));
        $router->post('/admin', fn (Request $req, array $p): Response => Response::html('x'));

        $response = $router->dispatch($this->request('DELETE', '/admin'));

        self::assertNotNull($response);
        /** @var Response $response */
        self::assertSame(405, $response->status);
        $allow = (string) $response->header('Allow');
        self::assertStringContainsString('GET', $allow);
        self::assertStringContainsString('POST', $allow);
        self::assertStringContainsString('HEAD', $allow, 'HEAD is offered wherever GET is');
    }

    public function test_head_is_served_by_the_get_route(): void
    {
        $router = new Router();
        $router->get('/page', fn (Request $req, array $p): Response => Response::html('body'));

        $response = $router->dispatch($this->request('HEAD', '/page'));

        self::assertNotNull($response);
        /** @var Response $response */
        self::assertSame(200, $response->status, 'HEAD matches the GET route');
        // The kernel strips the body; dispatch itself returns the GET response.
        self::assertSame('body', $response->body);
    }

    public function test_named_route_url_generation(): void
    {
        $router = new Router();
        $router->get('/admin/collections/{handle}/entries/{id}/edit', fn (Request $req, array $p): Response => Response::html('x'))->name('entries.edit');

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
        $router->get('/admin', function (Request $req, array $p) use (&$reached): Response {
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
            $g->get('/collections', fn (Request $req, array $p): Response => Response::html('list'))->name('collections');
        });

        self::assertSame('list', $router->dispatch($this->request('GET', '/admin/collections'))->body);
        self::assertSame(['mw'], $calls, 'group middleware ran');
        self::assertSame('/admin/collections', $router->url('collections'));
    }
}

<?php

declare(strict_types=1);

namespace Nimbus\Tests\Http;

use Nimbus\Http\HttpException;
use Nimbus\Http\Request;
use Nimbus\Http\Response;
use Nimbus\Http\Route;
use Nimbus\Http\Router;
use ReflectionFunction;

/**
 * The kernel's contract: every path in and out is a Response.
 *
 * The route-contract test uses reflection, but only here — production code
 * never introspects handlers; Router::routes() is a plain read-only accessor.
 */
final class ResponseContractTest extends HttpTestCase
{
    // ------------------------------------------------- every exit is a Response

    public function test_missing_route_returns_a_404_response(): void
    {
        $response = $this->get('/no/such/path');

        self::assertSame(404, $response->status);
        self::assertStringContainsString('Not found', $response->body);
    }

    public function test_the_404_body_escapes_the_requested_path(): void
    {
        $response = $this->throughKernel($this->request('GET', '/<script>alert(1)</script>'));

        self::assertSame(404, $response->status);
        self::assertStringNotContainsString('<script>', $response->body);
    }

    public function test_the_home_route_responds(): void
    {
        $response = $this->assertOkHtml($this->get('/'));

        self::assertStringContainsString('/admin', $response->body);
    }

    public function test_http_exception_becomes_its_own_response(): void
    {
        $router = new Router();
        $router->get('/boom', function (Request $req, array $p): Response {
            throw HttpException::redirect('/somewhere-else');
        });

        // The kernel catches it; here we prove the exception carries a Response.
        try {
            $router->dispatch($this->request('GET', '/boom'));
            self::fail('expected HttpException');
        } catch (HttpException $e) {
            self::assertSame(302, $e->response->status);
            self::assertSame('/somewhere-else', $e->response->header('Location'));
        }
    }

    public function test_a_guard_short_circuit_reaches_the_client_as_a_redirect(): void
    {
        // requireAdmin() throws HttpException; the kernel must turn it into a response.
        $this->makeCollection('posts');
        $this->actingAs('editor', 'editor@test.local');

        $response = $this->get('/admin/collections/new');

        self::assertSame(302, $response->status);
        self::assertSame('/admin/collections', $response->header('Location'));
    }

    public function test_an_unexpected_exception_becomes_a_generic_500_with_a_reference(): void
    {
        // A collection with a field label past VARCHAR(120) fails deep in the
        // write, with no typed exception for the controller to catch.
        $this->actingAs('admin');

        $response = $this->post('/admin/collections', [
            'name'   => 'Broken',
            'handle' => 'broken',
            'fields' => [['label' => str_repeat('x', 300), 'handle' => 'wide', 'type' => 'text']],
        ]);

        self::assertSame(500, $response->status);
        self::assertStringContainsString('Something went wrong', $response->body);
        self::assertMatchesRegularExpression('/Reference: <code>[0-9a-f]{8}<\/code>/', $response->body);
        // The internals must not leak.
        self::assertStringNotContainsString('SQLSTATE', $response->body);
        self::assertStringNotContainsString('nb_fields', $response->body);
    }

    // ------------------------------------------- item 9: route handler contract

    public function test_every_registered_route_declares_a_response_return_type(): void
    {
        $routes = $this->router->routes();
        self::assertNotEmpty($routes, 'the app must register routes');

        foreach ($routes as $route) {
            $handler = $route->handler();
            self::assertInstanceOf(\Closure::class, $handler, $this->label($route));

            $returnType = (new ReflectionFunction($handler))->getReturnType();
            self::assertNotNull($returnType, $this->label($route) . ' declares no return type');
            self::assertSame(Response::class, (string) $returnType, $this->label($route));
        }
    }

    public function test_every_registered_route_accepts_the_request_first(): void
    {
        foreach ($this->router->routes() as $route) {
            $params = (new ReflectionFunction($route->handler()))->getParameters();

            self::assertNotEmpty($params, $this->label($route) . ' takes no arguments');
            self::assertSame(
                Request::class,
                (string) $params[0]->getType(),
                $this->label($route) . ' must take the Request first',
            );
        }
    }

    public function test_dispatching_every_get_route_produces_a_response(): void
    {
        $this->actingAs('admin');
        $collection = $this->makeCollection('posts');
        $entryId    = $this->db->insert(
            "INSERT INTO nb_entries (collection_id, title, slug, status, data, created_at, updated_at)
             VALUES (:c, 'Fixture', 'fixture', 'draft', '{}', NOW(), NOW())",
            ['c' => $collection->id]
        );

        $substitutions = ['{id}' => (string) $collection->id, '{handle}' => 'posts'];

        foreach ($this->router->routes() as $route) {
            if ($route->method !== 'GET') {
                continue;
            }
            $path = strtr($route->pattern, $substitutions);
            // The entry routes need a real entry id, not a collection id.
            if (str_contains($route->pattern, '/entries/{id}')) {
                $path = str_replace('/entries/' . $collection->id, '/entries/' . $entryId, $path);
            }

            $response = $this->throughKernel($this->request('GET', $path === '' ? '/' : $path));

            self::assertInstanceOf(Response::class, $response, "GET {$path}");
            self::assertLessThan(500, $response->status, "GET {$path} returned {$response->status}");
        }
    }

    private function label(Route $route): string
    {
        return $route->method . ' ' . ($route->pattern === '' ? '/' : $route->pattern);
    }
}

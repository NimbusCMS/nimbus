<?php

declare(strict_types=1);

namespace Nimbus\Tests\Http;

use Nimbus\Api\ApiTokenRepository;

/**
 * HTTP method semantics through the real kernel (HTTP-2): HEAD is served by the
 * GET route with the body stripped; a matched path with the wrong method is a
 * 405 with an `Allow` header (not a 404); the site catch-alls mean a wrong-method
 * request to almost any path is a 405; and HEAD still runs the route's guards.
 */
final class HttpMethodTest extends HttpTestCase
{
    public function test_head_on_a_get_route_returns_200_with_no_body(): void
    {
        $response = $this->throughKernel($this->request('HEAD', '/'));

        self::assertSame(200, $response->status);
        self::assertSame('', $response->body, 'a HEAD carries no body');
        self::assertNotNull($response->header('Content-Type'), 'but the GET headers are preserved');
        self::assertNotNull($response->header('Content-Security-Policy'), 'security headers still applied');
    }

    public function test_a_wrong_method_on_a_matched_path_is_405_with_allow(): void
    {
        // `/` is the literal home GET route; POST to it matches the path, not the verb.
        $response = $this->throughKernel($this->request('POST', '/'));

        self::assertSame(405, $response->status);
        self::assertStringContainsString('GET', (string) $response->header('Allow'));
        self::assertStringContainsString('HEAD', (string) $response->header('Allow'));
        self::assertNotNull($response->header('Content-Security-Policy'), '405 carries security headers too');
    }

    public function test_the_site_catch_all_makes_a_wrong_method_path_a_405_not_404(): void
    {
        // Deliberate, documented consequence of `GET /{collection}`: a POST to a
        // single-segment path matches the catch-all pattern → 405 (Allow: GET,
        // HEAD), even though a GET there would itself 404 (unknown collection).
        $response = $this->throughKernel($this->request('POST', '/no-such-page'));

        self::assertSame(405, $response->status);
        self::assertStringContainsString('GET', (string) $response->header('Allow'));
    }

    public function test_a_path_matching_no_route_is_still_a_404(): void
    {
        // A 3-segment path matches no site/admin/api route pattern at all.
        self::assertSame(404, $this->throughKernel($this->request('GET', '/a/b/c/d'))->status);
        self::assertSame(404, $this->throughKernel($this->request('POST', '/a/b/c/d'))->status, 'no pattern match → 404, not 405');
    }

    public function test_head_still_runs_the_route_guards(): void
    {
        // HEAD to a protected admin GET → the auth middleware still redirects to login.
        $admin = $this->throughKernel($this->request('HEAD', '/admin'));
        self::assertSame(302, $admin->status);
        self::assertSame('/admin/login', $admin->header('Location'));

        // HEAD to a protected API GET without a token → the group's auth still 401s.
        $this->makeCollection('posts');
        $api = $this->throughKernel($this->request('HEAD', '/api/v1/collections/posts/entries'));
        self::assertSame(401, $api->status, 'HEAD cannot skip the API auth guard');
    }

    public function test_head_on_an_authenticated_api_get_returns_200_empty(): void
    {
        $token = (new ApiTokenRepository($this->db))->create('T', ['*:read']);
        $this->makeCollection('posts');

        $server  = ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_AUTHORIZATION' => 'Bearer ' . $token];
        $request = new \Nimbus\Http\Request('HEAD', '/api/v1/collections/posts/entries', [], [], $server, []);
        $response = $this->throughKernel($request);

        self::assertSame(200, $response->status);
        self::assertSame('', $response->body);
    }
}

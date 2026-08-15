<?php

declare(strict_types=1);

namespace Nimbus\Tests\Http;

use Nimbus\Application;

/**
 * Config-driven redirects, resolved before routing, through the real kernel.
 */
final class RedirectRoutesTest extends HttpTestCase
{
    /** @param array<string,array{to:string,status:int}> $redirects */
    private function appWith(array $redirects): Application
    {
        return new Application($this->db, $this->auth, $redirects);
    }

    public function test_a_configured_path_redirects(): void
    {
        $app = $this->appWith(['/old' => ['to' => '/posts/new', 'status' => 301]]);

        $response = $app->handle($this->request('GET', '/old'));

        self::assertSame(301, $response->status);
        self::assertSame('/posts/new', $response->header('Location'));
    }

    public function test_the_configured_status_is_honoured(): void
    {
        $app = $this->appWith(['/promo' => ['to' => '/posts/sale', 'status' => 302]]);

        self::assertSame(302, $app->handle($this->request('GET', '/promo'))->status);
    }

    public function test_an_unlisted_path_is_not_redirected(): void
    {
        $this->makeCollection('posts');
        $app = $this->appWith(['/old' => ['to' => '/new', 'status' => 301]]);

        // A real collection index must render, not redirect.
        self::assertSame(200, $app->handle($this->request('GET', '/posts'))->status);
    }

    public function test_redirects_do_not_shadow_admin(): void
    {
        // Even with a redirect configured, /admin keeps its own behaviour
        // (anonymous → login) because only the exact source path redirects.
        $app = $this->appWith(['/old' => ['to' => '/new', 'status' => 301]]);

        self::assertSame(302, $app->handle($this->request('GET', '/admin'))->status);
        self::assertSame('/admin/login', $app->handle($this->request('GET', '/admin'))->header('Location'));
    }

    public function test_no_redirects_configured_changes_nothing(): void
    {
        $this->makeCollection('posts');
        $app = $this->appWith([]);

        self::assertSame(200, $app->handle($this->request('GET', '/posts'))->status);
    }
}

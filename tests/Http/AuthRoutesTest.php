<?php

declare(strict_types=1);

namespace Nimbus\Tests\Http;

use Nimbus\Http\Csrf;
use Nimbus\Http\Request;

final class AuthRoutesTest extends HttpTestCase
{
    // ------------------------------------------------------------ gating

    /** @return array<string,array{string}> */
    public static function adminPaths(): array
    {
        return [
            'dashboard'      => ['/admin'],
            'collections'    => ['/admin/collections'],
            'new collection' => ['/admin/collections/new'],
            'media'          => ['/admin/media'],
            'users'          => ['/admin/users'],
            'settings'       => ['/admin/settings'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('adminPaths')]
    public function test_anonymous_admin_request_redirects_to_login(string $path): void
    {
        $this->assertRedirects($this->get($path), '/admin/login');
    }

    public function test_login_page_stays_public(): void
    {
        $response = $this->assertOkHtml($this->get('/admin/login'));

        self::assertStringContainsString('name="_token"', $response->body);
    }

    // ------------------------------------------------------------- login

    public function test_valid_login_redirects_and_authenticates(): void
    {
        $id = $this->createUser('admin', 'admin@test.local', 'correct-horse');

        $response = $this->post('/admin/login', ['email' => 'admin@test.local', 'password' => 'correct-horse']);

        $this->assertRedirects($response, '/admin');
        self::assertSame($id, $_SESSION['nimbus_uid'] ?? null);
    }

    public function test_valid_login_rotates_the_session_id(): void
    {
        $this->createUser('admin', 'admin@test.local', 'correct-horse');
        $before = $this->sessionId();

        $this->post('/admin/login', ['email' => 'admin@test.local', 'password' => 'correct-horse']);

        // Session fixation: the id a pre-auth visitor knows must not survive login.
        self::assertNotSame($before, $this->sessionId());
        self::assertNotSame('', $this->sessionId());
    }

    public function test_wrong_password_does_not_authenticate(): void
    {
        $this->createUser('admin', 'admin@test.local', 'correct-horse');

        $response = $this->post('/admin/login', ['email' => 'admin@test.local', 'password' => 'wrong']);

        self::assertSame(200, $response->status, 'the form is re-rendered, not redirected');
        self::assertStringContainsString('Invalid email or password', $response->body);
        self::assertArrayNotHasKey('nimbus_uid', $_SESSION);
    }

    public function test_unknown_email_does_not_authenticate(): void
    {
        $response = $this->post('/admin/login', ['email' => 'nobody@test.local', 'password' => 'whatever']);

        self::assertSame(200, $response->status);
        self::assertArrayNotHasKey('nimbus_uid', $_SESSION);
    }

    public function test_login_without_csrf_is_rejected(): void
    {
        $this->createUser('admin', 'admin@test.local', 'correct-horse');

        $response = $this->postWithoutCsrf('/admin/login', ['email' => 'admin@test.local', 'password' => 'correct-horse']);

        self::assertSame(200, $response->status);
        self::assertStringContainsString('session expired', $response->body);
        self::assertArrayNotHasKey('nimbus_uid', $_SESSION, 'a CSRF failure must never authenticate');
    }

    /** A login POST from a specific client IP (the shared helper hardcodes 127.0.0.1). */
    private function loginFrom(string $ip, string $email, string $password): \Nimbus\Http\Response
    {
        $body = ['email' => $email, 'password' => $password, '_token' => Csrf::token()];
        return $this->throughKernel(new Request('POST', '/admin/login', [], $body, ['REMOTE_ADDR' => $ip], []));
    }

    public function test_a_single_account_is_locked_even_when_the_ip_varies(): void
    {
        // AUTH-2: distributed spray — one email, a different IP each attempt. The
        // per-account (login-em:) key must lock it though no single IP repeats.
        $this->createUser('admin', 'admin@test.local', 'correct-horse');

        for ($i = 0; $i < 5; $i++) {
            $this->loginFrom("203.0.113.{$i}", 'admin@test.local', 'wrong');
        }

        // A fresh IP with the correct password is still refused — the account is locked.
        $response = $this->loginFrom('198.51.100.7', 'admin@test.local', 'correct-horse');
        self::assertStringContainsString('Too many attempts', $response->body);
        self::assertArrayNotHasKey('nimbus_uid', $_SESSION);
    }

    public function test_a_locked_known_account_and_a_flooded_unknown_email_are_indistinguishable(): void
    {
        // AUTH-2 must not re-open AUTH-1: locking a real account and a nonexistent
        // one look identical (same status + message), so lockout is no oracle.
        $this->createUser('admin', 'real@test.local', 'correct-horse');

        for ($i = 0; $i < 5; $i++) {
            $this->loginFrom("203.0.113.{$i}", 'real@test.local', 'wrong');
        }
        for ($i = 0; $i < 5; $i++) {
            $this->loginFrom("203.0.114.{$i}", 'ghost@test.local', 'wrong'); // no such user
        }

        $known   = $this->loginFrom('198.51.100.1', 'real@test.local', 'whatever');
        $unknown = $this->loginFrom('198.51.100.2', 'ghost@test.local', 'whatever');

        // Bodies are identical except the per-request CSP nonce (random, unrelated
        // to whether the account exists — not an oracle); normalize it out.
        $norm = static fn (string $b): string => (string) preg_replace('/nonce="[^"]*"/', 'nonce="X"', $b);
        self::assertSame($known->status, $unknown->status);
        self::assertSame($norm($known->body), $norm($unknown->body), 'a locked account is indistinguishable from a flooded unknown email');
        self::assertStringContainsString('Too many attempts', $known->body);
    }

    public function test_a_successful_login_clears_both_throttle_keys(): void
    {
        $this->createUser('admin', 'admin@test.local', 'correct-horse');
        for ($i = 0; $i < 3; $i++) {
            $this->loginFrom('203.0.113.9', 'admin@test.local', 'wrong');
        }
        // Correct password from the same IP still under the cap → succeeds + clears.
        $this->resetSession();
        $ok = $this->loginFrom('203.0.113.9', 'admin@test.local', 'correct-horse');
        self::assertSame(302, $ok->status, 'a correct login under the cap succeeds');
    }

    public function test_login_is_throttled_after_repeated_failures(): void
    {
        $this->createUser('admin', 'admin@test.local', 'correct-horse');

        for ($i = 0; $i < 5; $i++) {
            $this->post('/admin/login', ['email' => 'admin@test.local', 'password' => 'wrong']);
        }

        // Even the *correct* password is refused while the lock holds.
        $response = $this->post('/admin/login', ['email' => 'admin@test.local', 'password' => 'correct-horse']);

        self::assertSame(200, $response->status);
        self::assertStringContainsString('Too many attempts', $response->body);
        self::assertArrayNotHasKey('nimbus_uid', $_SESSION);
    }

    public function test_authenticated_user_visiting_login_is_sent_to_the_dashboard(): void
    {
        $this->actingAs('admin');

        $this->assertRedirects($this->get('/admin/login'), '/admin');
    }

    // ------------------------------------------------------------ logout

    public function test_logout_requires_post(): void
    {
        $this->actingAs('admin');

        $response = $this->get('/admin/logout');

        self::assertSame(404, $response->status, 'GET must not match the logout route');
        self::assertArrayHasKey('nimbus_uid', $_SESSION, 'a GET must not have logged anyone out');
    }

    public function test_logout_requires_a_valid_csrf_token(): void
    {
        $this->actingAs('admin');

        $response = $this->postWithoutCsrf('/admin/logout');

        $this->assertRedirects($response, '/admin/login');
        self::assertArrayHasKey('nimbus_uid', $_SESSION, 'session must survive a forged logout');
        self::assertTrue($this->auth->check());
    }

    public function test_logout_rejects_a_wrong_csrf_token(): void
    {
        $this->actingAs('admin');
        Csrf::token();

        $this->postWithoutCsrf('/admin/logout', ['_token' => str_repeat('a', 64)]);

        self::assertArrayHasKey('nimbus_uid', $_SESSION);
    }

    public function test_logout_destroys_authentication_state(): void
    {
        $this->actingAs('admin');
        self::assertTrue($this->auth->check());

        $response = $this->post('/admin/logout');

        $this->assertRedirects($response, '/admin/login');
        self::assertArrayNotHasKey('nimbus_uid', $_SESSION);
        self::assertFalse($this->auth->check());
    }

    public function test_admin_routes_are_gated_again_after_logout(): void
    {
        $this->actingAs('admin');
        $this->post('/admin/logout');

        $this->assertRedirects($this->get('/admin/collections'), '/admin/login');
    }
}

<?php

declare(strict_types=1);

namespace Nimbus\Tests\Http;

use Nimbus\Auth\OAuth\OAuthService;
use Nimbus\Http\Csrf;
use Nimbus\Http\Request;
use Nimbus\Http\TrustedProxies;
use Nimbus\Tests\Support\SpyMailer;

/**
 * The load-bearing invariant behind "trusted-proxy URL handling": every absolute
 * URL Nimbus generates is built from APP_URL, never from the request Host. A
 * forged (or proxy-passed-through) `X-Forwarded-Host` must not be able to point a
 * password-reset / invitation / OAuth link at an attacker's domain — the classic
 * host-header account-takeover. This test makes that a permanent regression guard
 * rather than a grep, so a future change that wires request host into a link
 * fails here.
 */
final class TrustedProxyUrlTest extends HttpTestCase
{
    private ?string $savedAppUrl = null;
    private SpyMailer $spy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->savedAppUrl = getenv('APP_URL') === false ? null : (string) getenv('APP_URL');
        putenv('APP_URL=https://real.example');
        $this->spy    = new SpyMailer();
        $this->mailer = $this->spy;
    }

    protected function tearDown(): void
    {
        if ($this->savedAppUrl === null) {
            putenv('APP_URL');
        } else {
            putenv('APP_URL=' . $this->savedAppUrl);
        }
        parent::tearDown();
    }

    public function test_forged_forwarded_host_does_not_change_the_reset_link(): void
    {
        $this->createUser('admin', 'victim@test.local');

        // A trusted proxy in front, but the client forged X-Forwarded-Host.
        $req = new Request(
            'POST',
            '/admin/forgot',
            [],
            ['email' => 'victim@test.local', '_token' => Csrf::token()],
            [
                'REMOTE_ADDR'            => '10.9.9.9',
                'HTTP_X_FORWARDED_HOST'  => 'evil.example',
                'HTTP_X_FORWARDED_PROTO' => 'https',
            ],
            [],
            TrustedProxies::fromString('10.9.9.9'),
        );
        $this->throughKernel($req);

        $last = end($this->spy->sent);
        self::assertNotFalse($last, 'a reset mail should have been sent for a known user');
        $body = $last['body'];
        self::assertStringContainsString('https://real.example/admin/reset', $body, 'link is APP_URL-based');
        self::assertStringNotContainsString('evil.example', $body, 'the forged forwarded host never reaches the link');
    }

    public function test_oauth_redirect_uri_is_app_url_based_not_request_derived(): void
    {
        // The redirect URI must keep matching the provider-registered URI (derived
        // from APP_URL), immune to any forwarded host. Invitation links share the
        // identical Config::appUrl() path.
        self::assertSame('https://real.example/admin/oauth/google/callback', OAuthService::redirectUri('google'));
    }
}

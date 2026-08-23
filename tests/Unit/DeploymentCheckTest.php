<?php

declare(strict_types=1);

namespace Nimbus\Tests\Unit;

use Nimbus\Http\Request;
use Nimbus\Http\TrustedProxies;
use Nimbus\Support\DeploymentCheck;
use PHPUnit\Framework\TestCase;

/**
 * The URL-generation misconfiguration diagnostic (ADR: trusted-proxy URL
 * handling). It must fire only for a real proxied deployment and only on
 * non-spoofable signals — never read or echo a forwarded host.
 */
final class DeploymentCheckTest extends TestCase
{
    private ?string $savedAppUrl = null;

    protected function setUp(): void
    {
        $this->savedAppUrl = getenv('APP_URL') === false ? null : (string) getenv('APP_URL');
    }

    protected function tearDown(): void
    {
        if ($this->savedAppUrl === null) {
            putenv('APP_URL');
        } else {
            putenv('APP_URL=' . $this->savedAppUrl);
        }
    }

    /**
     * @param array<string,mixed> $server
     */
    private function request(array $server, ?TrustedProxies $proxies = null): Request
    {
        return new Request('GET', '/admin/plugins', [], [], $server, [], $proxies);
    }

    public function test_a_direct_hit_never_warns_even_with_a_localhost_app_url(): void
    {
        putenv('APP_URL=http://localhost:8080');
        // No trusted proxies configured → not a proxied deployment.
        $req = $this->request(['REMOTE_ADDR' => '203.0.113.9']);

        self::assertSame([], DeploymentCheck::warnings($req));
    }

    public function test_localhost_app_url_behind_a_trusted_proxy_warns(): void
    {
        putenv('APP_URL=http://localhost:8080');
        $req = $this->request(['REMOTE_ADDR' => '10.0.0.5'], TrustedProxies::fromString('10.0.0.0/8'));

        $warnings = DeploymentCheck::warnings($req);
        self::assertCount(1, $warnings);
        self::assertStringContainsString('APP_URL', $warnings[0]);
        self::assertStringContainsString('localhost', $warnings[0]);
    }

    public function test_http_app_url_while_secure_behind_a_trusted_proxy_warns(): void
    {
        putenv('APP_URL=http://real.example');
        $req = $this->request(
            ['REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_PROTO' => 'https'],
            TrustedProxies::fromString('10.0.0.0/8'),
        );

        $warnings = DeploymentCheck::warnings($req);
        self::assertCount(1, $warnings);
        self::assertStringContainsString('http://', $warnings[0]);
    }

    public function test_a_correct_https_public_app_url_behind_a_proxy_is_clean(): void
    {
        putenv('APP_URL=https://real.example');
        $req = $this->request(
            ['REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_PROTO' => 'https'],
            TrustedProxies::fromString('10.0.0.0/8'),
        );

        self::assertSame([], DeploymentCheck::warnings($req));
    }

    public function test_a_forged_forwarded_host_cannot_appear_in_a_warning(): void
    {
        // Even behind a trusted proxy the forwarded host is client-spoofable; the
        // diagnostic must never surface it (no reflected attacker string).
        putenv('APP_URL=http://localhost:8080');
        $req = $this->request(
            ['REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_HOST' => 'evil.example'],
            TrustedProxies::fromString('10.0.0.0/8'),
        );

        foreach (DeploymentCheck::warnings($req) as $warning) {
            self::assertStringNotContainsString('evil.example', $warning);
        }
    }

    public function test_via_trusted_proxy_reflects_peer_trust(): void
    {
        self::assertTrue(
            $this->request(['REMOTE_ADDR' => '10.0.0.5'], TrustedProxies::fromString('10.0.0.0/8'))->viaTrustedProxy(),
        );
        self::assertFalse(
            $this->request(['REMOTE_ADDR' => '203.0.113.9'], TrustedProxies::fromString('10.0.0.0/8'))->viaTrustedProxy(),
        );
        self::assertFalse(
            $this->request(['REMOTE_ADDR' => '10.0.0.5'])->viaTrustedProxy(), // no proxies configured
        );
    }
}

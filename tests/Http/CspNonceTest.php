<?php

declare(strict_types=1);

namespace Nimbus\Tests\Http;

/**
 * The nonce-based CSP hardening. The load-bearing properties: script-src is
 * nonce-only (no 'unsafe-inline' — the regression guard), the nonce is a fresh
 * CSPRNG value per request, and the value in the CSP header matches the nonce on
 * the rendered inline <script> (so the plumbing actually lines up).
 */
final class CspNonceTest extends HttpTestCase
{
    private function csp(string $body = '/admin/login'): string
    {
        return (string) $this->get($body)->header('Content-Security-Policy');
    }

    public function test_script_src_is_nonce_only_never_unsafe_inline(): void
    {
        $csp = $this->csp();

        self::assertMatchesRegularExpression("/script-src[^;]*'nonce-[A-Za-z0-9+\/=]{16,}'/", $csp);
        // The crux: with a nonce present, a stray 'unsafe-inline' would negate it.
        $scriptSrc = '';
        foreach (explode(';', $csp) as $dir) {
            if (str_contains(trim($dir), 'script-src')) {
                $scriptSrc = trim($dir);
            }
        }
        self::assertStringNotContainsString("'unsafe-inline'", $scriptSrc, "script-src must not carry 'unsafe-inline'");
    }

    public function test_the_nonce_is_fresh_per_request(): void
    {
        preg_match("/script-src[^;]*'nonce-([A-Za-z0-9+\/=]+)'/", $this->csp(), $a);
        preg_match("/script-src[^;]*'nonce-([A-Za-z0-9+\/=]+)'/", $this->csp(), $b);

        self::assertNotSame('', $a[1] ?? '');
        self::assertNotSame($a[1] ?? '', $b[1] ?? '', 'a new nonce every request');
    }

    public function test_the_header_nonce_matches_the_rendered_script(): void
    {
        $resp  = $this->get('/admin/login');
        $csp   = (string) $resp->header('Content-Security-Policy');
        preg_match("/'nonce-([A-Za-z0-9+\/=]+)'/", $csp, $m);
        $nonce = $m[1] ?? '';

        self::assertNotSame('', $nonce);
        // The login page has no <script>, so drive an admin shell page that does.
        $this->actingAs('admin');
        $shell = $this->get('/admin/settings');
        $shellCsp = (string) $shell->header('Content-Security-Policy');
        preg_match("/'nonce-([A-Za-z0-9+\/=]+)'/", $shellCsp, $sm);
        self::assertStringContainsString('nonce="' . ($sm[1] ?? '') . '"', $shell->body, 'inline <script> carries the request nonce');
    }

    public function test_destructive_forms_use_data_confirm_not_inline_onsubmit(): void
    {
        $this->makeCollection('posts');
        $this->actingAs('admin');

        $body = $this->get('/admin/collections')->body;
        self::assertStringContainsString('data-confirm=', $body);
        self::assertStringNotContainsString('onsubmit=', $body, 'no inline event handler (CSP)');
    }
}

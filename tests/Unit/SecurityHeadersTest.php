<?php

declare(strict_types=1);

namespace Nimbus\Tests\Unit;

use Nimbus\Http\Response;
use Nimbus\Http\SecurityHeaders;
use PHPUnit\Framework\TestCase;

final class SecurityHeadersTest extends TestCase
{
    public function test_baseline_headers(): void
    {
        $h = SecurityHeaders::all();

        self::assertSame('nosniff', $h['X-Content-Type-Options']);
        self::assertSame('DENY', $h['X-Frame-Options']);
        self::assertSame('same-origin', $h['Referrer-Policy']);

        $csp = $h['Content-Security-Policy'];
        self::assertStringContainsString("default-src 'self'", $csp);
        self::assertStringContainsString("object-src 'none'", $csp);
        self::assertStringContainsString("frame-ancestors 'none'", $csp);
        self::assertStringContainsString("form-action 'self'", $csp);
    }

    public function test_apply_adds_headers_without_touching_body(): void
    {
        $r = SecurityHeaders::apply(Response::html('<h1>hi</h1>', 200));

        self::assertSame('<h1>hi</h1>', $r->body);
        self::assertArrayHasKey('Content-Security-Policy', $r->headers);
        self::assertSame('nosniff', $r->headers['X-Content-Type-Options']);
        self::assertSame('text/html; charset=UTF-8', $r->headers['Content-Type']); // original kept
    }

    // ---------------------------------------------------- embedding allowlist

    /**
     * @param list<string> $src
     * @param list<string> $ancestors
     * @return array<string,string>
     */
    private function with(array $src, array $ancestors): array
    {
        return SecurityHeaders::all(['frame_src' => $src, 'frame_ancestors' => $ancestors]);
    }

    public function test_empty_embedding_is_byte_identical_to_the_locked_default(): void
    {
        // Passing empty lists must produce exactly the same headers as no argument.
        self::assertSame(SecurityHeaders::all(), $this->with([], []));

        $h = $this->with([], []);
        self::assertSame('DENY', $h['X-Frame-Options']);
        self::assertStringContainsString("frame-ancestors 'none'", $h['Content-Security-Policy']);
        self::assertStringNotContainsString('frame-src', $h['Content-Security-Policy']);
    }

    public function test_frame_src_opens_outbound_embedding_only(): void
    {
        $h   = $this->with(['https://numistoria.danmat.dev', 'https://danmat.github.io'], []);
        $csp = $h['Content-Security-Policy'];

        self::assertStringContainsString("frame-src 'self' https://numistoria.danmat.dev https://danmat.github.io", $csp);
        // Inbound framing is untouched: still locked down.
        self::assertStringContainsString("frame-ancestors 'none'", $csp);
        self::assertSame('DENY', $h['X-Frame-Options']);
    }

    public function test_frame_ancestors_opens_inbound_and_drops_x_frame_options(): void
    {
        $h   = $this->with([], ['https://danmat.dev']);
        $csp = $h['Content-Security-Policy'];

        self::assertStringContainsString("frame-ancestors 'self' https://danmat.dev", $csp);
        self::assertArrayNotHasKey('X-Frame-Options', $h, 'XFO is dropped so it cannot override frame-ancestors');
        self::assertStringNotContainsString('frame-src', $csp, 'outbound stays default');
    }

    public function test_both_lists_together(): void
    {
        $h   = $this->with(['https://a.test'], ['https://b.test', 'https://c.test']);
        $csp = $h['Content-Security-Policy'];

        self::assertStringContainsString("frame-src 'self' https://a.test", $csp);
        self::assertStringContainsString("frame-ancestors 'self' https://b.test https://c.test", $csp);
        self::assertArrayNotHasKey('X-Frame-Options', $h);
        // The rest of the policy is unchanged.
        self::assertStringContainsString("default-src 'self'", $csp);
        self::assertStringContainsString("object-src 'none'", $csp);
        self::assertStringContainsString("form-action 'self'", $csp);
    }
}

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
}

<?php

declare(strict_types=1);

namespace Nimbus\Tests\Unit;

use Nimbus\Http\Response;
use PHPUnit\Framework\TestCase;

final class ResponseTest extends TestCase
{
    public function test_html(): void
    {
        $r = Response::html('<h1>Hi</h1>', 201);
        self::assertSame(201, $r->status);
        self::assertSame('<h1>Hi</h1>', $r->body);
        self::assertSame('text/html; charset=UTF-8', $r->headers['Content-Type']);
    }

    public function test_redirect(): void
    {
        $r = Response::redirect('/admin');
        self::assertSame(302, $r->status);
        self::assertSame('/admin', $r->headers['Location']);
        self::assertSame('', $r->body);
    }

    public function test_redirect_rejects_a_non_redirect_status(): void
    {
        self::assertSame(301, Response::redirect('/a', 301)->status);
        self::assertSame(303, Response::redirect('/a', 303)->status);

        $this->expectException(\InvalidArgumentException::class);
        Response::redirect('/admin', 200);
    }

    public function test_json_declares_utf8_and_keeps_unicode_readable(): void
    {
        $r = Response::json(['a' => 1, 'url' => '/x/y', 'name' => 'café']);
        self::assertSame('application/json; charset=UTF-8', $r->headers['Content-Type']);
        self::assertSame('{"a":1,"url":"/x/y","name":"café"}', $r->body);
    }

    public function test_json_propagates_encoding_failures(): void
    {
        $this->expectException(\JsonException::class);
        Response::json(['bad' => "\xB1\x31"]); // invalid UTF-8
    }

    public function test_download_filename(): void
    {
        $r = Response::download('col1,col2', 'export.csv', 'text/csv');
        self::assertSame('text/csv', $r->headers['Content-Type']);
        self::assertSame('attachment; filename="export.csv"', $r->headers['Content-Disposition']);
    }

    public function test_download_filename_falls_back_to_ascii_and_adds_filename_star(): void
    {
        $d = Response::download('x', 'rapport café.csv')->headers['Content-Disposition'];

        // "é" is two UTF-8 bytes, so the ASCII fallback carries two underscores;
        // the real name survives in filename*.
        self::assertStringContainsString('filename="rapport caf__.csv"', $d);
        self::assertStringContainsString("filename*=UTF-8''rapport%20caf%C3%A9.csv", $d);
    }

    public function test_download_filename_cannot_break_out_of_the_header(): void
    {
        $d = Response::download('x', "evil\r\nX-Injected: 1.csv")->headers['Content-Disposition'];

        self::assertStringNotContainsString("\r", $d);
        self::assertStringNotContainsString("\n", $d);
    }

    public function test_download_filename_drops_path_separators(): void
    {
        $d = Response::download('x', '../../etc/passwd')->headers['Content-Disposition'];

        self::assertSame('attachment; filename="....etcpasswd"', $d);
        self::assertStringNotContainsString('/', $d);
        self::assertStringNotContainsString('\\', $d);
    }

    public function test_with_header_is_immutable(): void
    {
        $r  = Response::html('x');
        $r2 = $r->withHeader('X-Test', '1');
        self::assertArrayNotHasKey('X-Test', $r->headers);
        self::assertSame('1', $r2->headers['X-Test']);
    }

    public function test_with_header_replaces_case_insensitively(): void
    {
        $r = Response::html('x')->withHeader('content-type', 'text/plain');

        self::assertSame('text/plain', $r->header('Content-Type'));
        self::assertCount(1, $r->headers, 'must replace, not accumulate a second Content-Type');
    }

    public function test_header_lookup_is_case_insensitive(): void
    {
        $r = Response::html('x')->withHeader('X-Ref', 'abc');

        self::assertSame('abc', $r->header('x-ref'));
        self::assertSame('text/html; charset=UTF-8', $r->header('CONTENT-TYPE'));
        self::assertNull($r->header('X-Missing'));
    }

    /** @return array<string,array{string,string}> */
    public static function injectionProvider(): array
    {
        return [
            'CR in value'    => ['X-Test', "a\rSet-Cookie: b=1"],
            'LF in value'    => ['X-Test', "a\nSet-Cookie: b=1"],
            'NUL in value'   => ['X-Test', "a\0b"],
            'space in name'  => ['X Test', 'a'],
            'colon in name'  => ['X:Test', 'a'],
            'newline in name' => ["X-Test\r\nEvil", 'a'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('injectionProvider')]
    public function test_headers_reject_injection(string $name, string $value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Response::html('x')->withHeader($name, $value);
    }

    public function test_redirect_location_cannot_carry_a_newline(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Response::redirect("/admin\r\nSet-Cookie: admin=1");
    }

    public function test_with_cookie_sets_a_hardened_set_cookie_header(): void
    {
        $res = Response::html('x')->withCookie('cart', 'tok123', 3600);
        $sc  = $res->headers['Set-Cookie'] ?? '';

        self::assertStringContainsString('cart=tok123', $sc);
        self::assertStringContainsString('Path=/', $sc);
        self::assertStringContainsString('Max-Age=3600', $sc);
        self::assertStringContainsString('SameSite=Lax', $sc);
        self::assertStringContainsString('Secure', $sc);
        self::assertStringContainsString('HttpOnly', $sc);
    }

    public function test_request_reads_a_cookie(): void
    {
        $req = new \Nimbus\Http\Request('GET', '/', [], [], [], [], null, '', ['cart' => 'tok123']);
        self::assertSame('tok123', $req->cookie('cart'));
        self::assertNull($req->cookie('absent'));
    }
}

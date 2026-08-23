<?php

declare(strict_types=1);

namespace Nimbus\Tests\Unit;

use Nimbus\Http\Csp;
use PHPUnit\Framework\TestCase;

/**
 * The nonce lifecycle: rotate() mints a fresh value; adopt() re-emits a stored
 * one on a cache hit but only if it is the exact emitted shape — a corrupt or
 * legacy value must never be echoed into the CSP header.
 */
final class CspTest extends TestCase
{
    public function test_rotate_produces_a_valid_nonce(): void
    {
        Csp::rotate();
        self::assertTrue(Csp::isValid(Csp::nonce()));
    }

    public function test_is_valid_accepts_the_emitted_shape_and_rejects_others(): void
    {
        self::assertTrue(Csp::isValid(base64_encode(random_bytes(16))));

        self::assertFalse(Csp::isValid(''), 'empty is not a nonce');
        self::assertFalse(Csp::isValid('<!doctype html>'), 'an HTML line is not a nonce');
        self::assertFalse(Csp::isValid("abc\ndef"), 'a newline can never appear in a nonce');
        self::assertFalse(Csp::isValid("nonce' ; script-src 'unsafe-inline"), 'no policy-token injection');
        self::assertFalse(Csp::isValid('short=='), 'wrong length');
    }

    public function test_adopt_re_emits_a_valid_stored_nonce(): void
    {
        $stored = base64_encode(random_bytes(16));
        Csp::adopt($stored);
        self::assertSame($stored, Csp::nonce());
    }

    public function test_adopt_rejects_an_invalid_value_and_falls_back_to_a_fresh_nonce(): void
    {
        Csp::adopt("<!doctype html>\n<body>");
        $result = Csp::nonce();

        self::assertNotSame("<!doctype html>\n<body>", $result, 'never echo untrusted text');
        self::assertTrue(Csp::isValid($result), 'a fresh valid nonce is used instead');
    }
}

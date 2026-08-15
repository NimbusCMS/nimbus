<?php

declare(strict_types=1);

namespace Nimbus\Tests\Http;

use Nimbus\Http\FormNonce;

/**
 * Single-use form nonces. Extends HttpTestCase only for its real session
 * handling — the nonces live in $_SESSION, like CSRF tokens.
 */
final class FormNonceTest extends HttpTestCase
{
    public function test_a_nonce_is_accepted_once_then_rejected(): void
    {
        $nonce = FormNonce::issue();

        self::assertTrue(FormNonce::consume($nonce));
        self::assertFalse(FormNonce::consume($nonce), 'a nonce cannot be used twice');
    }

    public function test_an_unknown_or_empty_nonce_is_rejected(): void
    {
        self::assertFalse(FormNonce::consume('never-issued'));
        self::assertFalse(FormNonce::consume(null));
        self::assertFalse(FormNonce::consume(''));
    }

    public function test_several_outstanding_nonces_are_each_valid(): void
    {
        $a = FormNonce::issue();
        $b = FormNonce::issue();

        // Two open forms / tabs: both work, order-independent.
        self::assertTrue(FormNonce::consume($b));
        self::assertTrue(FormNonce::consume($a));
    }
}

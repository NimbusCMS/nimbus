<?php

declare(strict_types=1);

namespace Nimbus\Http;

/**
 * The per-request Content-Security-Policy nonce.
 *
 * A single random value, generated once per request, that appears in BOTH the
 * `script-src 'nonce-…'` directive ({@see SecurityHeaders}) and the `nonce="…"`
 * attribute of every server-rendered inline `<script>`. Because `script-src`
 * drops `'unsafe-inline'`, only scripts carrying this nonce run — a stored or
 * reflected XSS payload cannot execute inline script without it.
 *
 * This is a request-scoped **secret**, not application state: {@see rotate()} is
 * called once at the top of {@see \Nimbus\Application::handle()} so every request
 * (including a persistent-worker context) gets a fresh, unguessable value, and
 * tests are isolated. It is never logged, never in a URL, and only ever placed in
 * the CSP header and trusted `<script nonce>` tags.
 */
final class Csp
{
    private static ?string $nonce = null;

    /** Start a fresh nonce for this request. Called once, before anything renders. */
    public static function rotate(): void
    {
        self::$nonce = base64_encode(random_bytes(16)); // 128 bits of CSPRNG entropy
    }

    /** The current request's nonce (generated on first use if rotate() hasn't run). */
    public static function nonce(): string
    {
        if (self::$nonce === null) {
            self::rotate();
        }
        return (string) self::$nonce;
    }
}

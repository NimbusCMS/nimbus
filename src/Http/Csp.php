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
 * This is a request-scoped **secret**, not application state. It has two lifecycle
 * verbs: {@see rotate()} mints a fresh, unguessable value — called once at the top
 * of {@see \Nimbus\Application::handle()}, so every request (including a
 * persistent-worker context) starts fresh and tests are isolated. {@see adopt()}
 * re-emits a *stored* value on a page-cache hit, so the nonce in the CSP header
 * matches the one baked into the cached HTML (a cached public page is a byte-exact
 * replay of one server render; its nonce is stable for the cache entry's life —
 * safe, because escape-by-default means an attacker cannot write new markup into
 * that immutable render). Outside a cache hit, every request rotates.
 *
 * It is never logged, never in a URL, and only ever placed in the CSP header and
 * trusted `<script nonce>` tags.
 */
final class Csp
{
    /** The shape rotate() emits: base64 of 16 bytes — 22 chars + "==". */
    private const PATTERN = '/^[A-Za-z0-9+\/]{22}==$/';

    private static ?string $nonce = null;

    /** Start a fresh nonce for this request. Called once, before anything renders. */
    public static function rotate(): void
    {
        self::$nonce = base64_encode(random_bytes(16)); // 128 bits of CSPRNG entropy
    }

    /**
     * Re-emit a nonce stored with a cached page, so the header matches the cached
     * body. Rejects anything that is not the exact shape rotate() emits and falls
     * back to a fresh nonce — so a corrupt or legacy cache entry can never inject
     * arbitrary text into the CSP header.
     */
    public static function adopt(string $nonce): void
    {
        self::$nonce = self::isValid($nonce) ? $nonce : base64_encode(random_bytes(16));
    }

    /** Whether a value is exactly the nonce shape rotate() emits. */
    public static function isValid(string $nonce): bool
    {
        return preg_match(self::PATTERN, $nonce) === 1;
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

<?php

declare(strict_types=1);

namespace Nimbus\Http;

/**
 * Baseline security response headers, applied to every response by the kernel.
 *
 * Both `script-src` and `style-src` are **nonce-only** ({@see Csp}) — no
 * 'unsafe-inline' on either, so an injected inline `<script>` or `<style>`/`style=`
 * cannot run. Every server-rendered inline `<style>`/`<script>` carries the
 * per-request nonce; inline `style=` attributes are not permitted (they cannot be
 * nonce'd) and are refactored to classes. The CSP also blocks external scripts,
 * objects, framing, base-uri hijacking and cross-origin posts. Never re-add
 * 'unsafe-inline' to either directive — it would negate the nonce.
 */
final class SecurityHeaders
{
    /** @return array<string,string> */
    public static function all(): array
    {
        $csp = implode('; ', [
            "default-src 'self'",
            "img-src 'self' data:",
            // Both directives are nonce-only — 'unsafe-inline' is REMOVED (a
            // browser ignores it once a nonce is present, so leaving it would
            // mask a missed block). Only server-rendered <script nonce="…"> /
            // <style nonce="…"> run; inline style= attributes are disallowed.
            "style-src 'self' 'nonce-" . Csp::nonce() . "'",
            "script-src 'self' 'nonce-" . Csp::nonce() . "'",
            "object-src 'none'",
            "base-uri 'self'",
            "frame-ancestors 'none'",
            "form-action 'self'",
        ]);

        return [
            'Content-Security-Policy' => $csp,
            'X-Content-Type-Options'  => 'nosniff',
            'X-Frame-Options'         => 'DENY',
            'Referrer-Policy'         => 'same-origin',
        ];
    }

    public static function apply(Response $response): Response
    {
        foreach (self::all() as $name => $value) {
            // A response may deliberately harden a header (e.g. the password-reset
            // page sets Referrer-Policy: no-referrer); the secure default only
            // fills in what the response did not already set.
            if ($response->header($name) === null) {
                $response = $response->withHeader($name, $value);
            }
        }
        return $response;
    }
}

<?php

declare(strict_types=1);

namespace Nimbus\Http;

/**
 * Baseline security response headers, applied to every response by the kernel.
 *
 * `script-src` is **nonce-only** ({@see Csp}) — no 'unsafe-inline', so an
 * injected inline `<script>` cannot run. `style-src` still allows 'unsafe-inline'
 * (a deferred, lower-severity call — CSS injection is not code execution, and a
 * dynamic theme swatch uses an inline style attribute). The CSP also blocks
 * external scripts, objects, framing, base-uri hijacking and cross-origin posts.
 * Never re-add 'unsafe-inline' to script-src — it would negate the nonce.
 */
final class SecurityHeaders
{
    /** @return array<string,string> */
    public static function all(): array
    {
        $csp = implode('; ', [
            "default-src 'self'",
            "img-src 'self' data:",
            // style-src keeps 'unsafe-inline' (deferred): CSS injection is low
            // severity and a dynamic swatch uses an inline style attribute.
            "style-src 'self' 'unsafe-inline'",
            // script-src is nonce-only — 'unsafe-inline' is REMOVED (a browser
            // ignores it once a nonce is present, so leaving it would mask a
            // missed block). Only server-rendered <script nonce="…"> runs.
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

<?php

declare(strict_types=1);

namespace Nimbus\Http;

use Nimbus\Support\Config;

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
    /**
     * @param array{frame_src:list<string>,frame_ancestors:list<string>}|null $embedding
     *        the per-site framing allowlists; null reads {@see Config::embedding()}.
     *        Both empty (the default) → byte-identical to the locked-down baseline.
     * @return array<string,string>
     */
    public static function all(?array $embedding = null): array
    {
        $embedding      ??= Config::embedding();
        $frameSrc        = $embedding['frame_src'];
        $frameAncestors  = $embedding['frame_ancestors'];

        $directives = [
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
        ];
        // frame-src: absent by default (falls back to default-src 'self'); emitted
        // only when the site opts specific origins in.
        if ($frameSrc !== []) {
            $directives[] = "frame-src 'self' " . implode(' ', $frameSrc);
        }
        // frame-ancestors: 'none' by default; 'self' + the listed origins when the
        // owner opts in. Kept in the same slot so the empty case is byte-identical.
        $directives[]   = $frameAncestors === []
            ? "frame-ancestors 'none'"
            : "frame-ancestors 'self' " . implode(' ', $frameAncestors);
        $directives[]   = "form-action 'self'";

        $headers = [
            'Content-Security-Policy' => implode('; ', $directives),
            'X-Content-Type-Options'  => 'nosniff',
            'Referrer-Policy'         => 'same-origin',
        ];
        // X-Frame-Options can only say DENY/SAMEORIGIN/one ALLOW-FROM, so it can't
        // represent a multi-origin allowlist — and a lingering DENY would override
        // frame-ancestors. Keep it (DENY) only while frame-ancestors is default-deny.
        if ($frameAncestors === []) {
            $headers['X-Frame-Options'] = 'DENY';
        }
        return $headers;
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

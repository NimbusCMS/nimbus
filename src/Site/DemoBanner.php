<?php

declare(strict_types=1);

namespace Nimbus\Site;

use Nimbus\Http\Csp;
use Nimbus\Support\Config;

/**
 * The public "this is a live demo" banner, rendered by core when a site runs in
 * demo mode (`NIMBUS_DEMO=true`) — not by any theme.
 *
 * Keeping it here means a public demo (the hosted sandbox) can run **any** theme
 * and still tell visitors what they're looking at, and no theme has to carry
 * demo-specific markup or the demo login. The credentials come from the same env
 * the admin login pre-fill uses (`NIMBUS_DEMO_EMAIL`/`_PASSWORD`), so nothing is
 * hardcoded.
 *
 * It is injected right after the theme's `<body>`, with its styles in a
 * nonce-carrying `<style>` (the site CSP is nonce-only — inline `style=` would be
 * dropped). Off by default: on a normal install `inject()` returns the page
 * untouched.
 */
final class DemoBanner
{
    /** Insert the banner into a rendered page when demo mode is on; otherwise return it unchanged. */
    public static function inject(string $html): string
    {
        if (!Config::demo()) {
            return $html;
        }
        return preg_replace('/(<body\b[^>]*>)/i', '$1' . self::markup(), $html, 1) ?? $html;
    }

    private static function markup(): string
    {
        $nonce = htmlspecialchars(Csp::nonce(), ENT_QUOTES);
        $email = htmlspecialchars(Config::demoEmail(), ENT_QUOTES);
        $pass  = htmlspecialchars(Config::demoPassword(), ENT_QUOTES);

        $creds = $email !== ''
            ? ' Sign in at <a href="/admin">/admin</a> with <code>' . $email . '</code> / <code>' . $pass . '</code>.'
            : '';

        $css = '.nb-demo-banner{background:#5751d6;color:#fff;text-align:center;padding:10px 16px;font-size:.95rem;line-height:1.45}'
            . '.nb-demo-banner a{color:#fff;text-decoration:underline;font-weight:600}'
            . '.nb-demo-banner code{background:rgba(255,255,255,.2);padding:1px 5px;border-radius:4px;font-size:.9em}';

        return '<style nonce="' . $nonce . '">' . $css . '</style>'
            . '<div class="nb-demo-banner">🧹 <strong>Live demo.</strong> '
            . 'A public NimbusCMS sandbox — it resets hourly, so nothing here is permanent.' . $creds . '</div>';
    }
}

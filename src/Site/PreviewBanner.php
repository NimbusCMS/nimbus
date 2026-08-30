<?php

declare(strict_types=1);

namespace Nimbus\Site;

use Nimbus\Http\Csp;

/**
 * The "you are viewing an unpublished draft" banner, injected by core into a
 * preview render (ADR 0021) — not by any theme, so preview works under any theme.
 *
 * Injected right after the theme's `<body>`, with its styles in a nonce-carrying
 * `<style>` (the site CSP is nonce-only — inline `style=` would be dropped),
 * mirroring {@see DemoBanner}. Unlike the demo banner this is always shown when
 * called: the caller (the SiteController preview branch) has already decided the
 * request is a valid preview.
 */
final class PreviewBanner
{
    public static function inject(string $html): string
    {
        return preg_replace('/(<body\b[^>]*>)/i', '$1' . self::markup(), $html, 1) ?? $html;
    }

    private static function markup(): string
    {
        $nonce = htmlspecialchars(Csp::nonce(), ENT_QUOTES);

        $css = '.nb-preview-banner{background:#b97a0a;color:#fff;text-align:center;padding:10px 16px;font-size:.95rem;line-height:1.45}'
            . '.nb-preview-banner strong{font-weight:700}';

        return '<style nonce="' . $nonce . '">' . $css . '</style>'
            . '<div class="nb-preview-banner">👁 <strong>Preview.</strong> '
            . 'This is an unpublished draft — it is not visible to the public.</div>';
    }
}

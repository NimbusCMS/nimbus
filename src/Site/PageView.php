<?php

declare(strict_types=1);

namespace Nimbus\Site;

/**
 * The view-model a plugin **page section** resolver returns (ADR 0023): which
 * theme template to render, the data to hand it, the page's SEO meta, and the
 * HTTP status. Core renders this through the active theme exactly as it renders a
 * content page — the plugin supplies data and a default template, never HTML and
 * never a layout.
 *
 * A resolver returns `null` instead of a PageView to mean "not here" — core then
 * serves the themed 404, so a section owns its own not-found (an unknown SKU, a
 * bad sub-path) without leaking which it was.
 */
final class PageView
{
    /**
     * @param string               $template the template name (e.g. `shop-index`); resolved
     *                                        theme-first, then the section's own templates (ADR 0023)
     * @param array<string,mixed>  $data     values handed to the template (escaped on render)
     * @param array{title?:string,description?:string,og_type?:string} $meta SEO meta for <head>
     * @param int                  $status   HTTP status (200 default; a section may 404/410 within itself)
     * @param bool                 $private  a per-user page (a cart, an account) — the response is
     *                                       marked `Cache-Control: no-store, private` + `noindex` so a
     *                                       shared CDN never serves one visitor's page to another. Section
     *                                       paths already bail the server page-cache (ADR 0023); this adds
     *                                       the response headers for the browser/CDN.
     */
    public function __construct(
        public string $template,
        public array $data = [],
        public array $meta = [],
        public int $status = 200,
        public bool $private = false,
    ) {
    }
}

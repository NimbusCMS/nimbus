<?php

declare(strict_types=1);

namespace Nimbus\Site;

/**
 * A data-only description of the public page being rendered, handed to head
 * contributors (see HeadContributor). It carries the same prepared view-model
 * the theme receives — never a service or the database.
 *
 * SECURITY — its string values are UNTRUSTED. `title`, `siteName` and the
 * view-model derive from editor/admin input (entry fields, the `site.title`
 * setting). A contributor emits into the raw `<head>`, so it MUST escape every
 * value it embeds — `View::e()` for an attribute/text sink, `json_encode` for a
 * JSON-LD block. Do not assume any field is safe because it "looks like config".
 *
 * The one exception is `$cspNonce`: a core-generated CSP nonce (see Http\Csp) a
 * contributor puts in `<script nonce="…">` so its script runs under the page's
 * content-security-policy. It is a fixed base64 shape, not editor input; passing
 * it through `View::e()` is still safe and the recommended habit.
 */
final class PageContext
{
    /**
     * @param string $kind 'home', 'collection', or 'entry' today — a plain
     *     string, not a frozen enum, so a contributor tolerates kinds core may
     *     add later (handle the ones you know, ignore the rest)
     * @param string $cspNonce the page's CSP nonce, for a `<script nonce>` a
     *     contributor emits (the one trusted-to-embed value — see the docblock)
     * @param array<string,mixed>|null      $entry      the entry view-model, on an entry page
     * @param array{handle:string,name:string}|null $collection on a collection page
     */
    public function __construct(
        public readonly string $kind,
        public readonly string $canonical,
        public readonly string $title,
        public readonly string $siteName,
        public readonly string $cspNonce,
        public readonly ?array $entry = null,
        public readonly ?array $collection = null,
    ) {
    }
}

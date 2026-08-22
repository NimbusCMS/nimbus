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
 */
final class PageContext
{
    /**
     * @param string $kind 'home', 'collection', or 'entry' today — a plain
     *     string, not a frozen enum, so a contributor tolerates kinds core may
     *     add later (handle the ones you know, ignore the rest)
     * @param array<string,mixed>|null      $entry      the entry view-model, on an entry page
     * @param array{handle:string,name:string}|null $collection on a collection page
     */
    public function __construct(
        public readonly string $kind,
        public readonly string $canonical,
        public readonly string $title,
        public readonly string $siteName,
        public readonly ?array $entry = null,
        public readonly ?array $collection = null,
    ) {
    }
}

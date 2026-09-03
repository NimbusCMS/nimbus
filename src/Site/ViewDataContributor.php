<?php

declare(strict_types=1);

namespace Nimbus\Site;

/**
 * Contributes **view data** to the body of a public content page — live,
 * page-contextual data a theme renders (featured products, related posts, popular
 * tags, and the like). Registered by a plugin through PluginContext::viewData();
 * see ADR 0027. The body-data sibling of {@see HeadContributor}.
 *
 * A contributor is handed the prepared {@see PageContext} and returns an
 * associative array (or [] for none). The result is merged under the plugin's own
 * namespace into the theme's `contrib` view key — it can never reach or overwrite
 * a core template variable. It returns **data, not HTML**: the theme escapes every
 * value on render (unlike the head seam, which emits trusted markup).
 *
 * SECURITY — VISITOR-INDEPENDENT DATA ONLY. Content pages are page-cached by path
 * (+ bounded ?page) with no cookie/query vary (Application::cacheKey), so whatever
 * a contributor returns is baked into the shared cached HTML and served to every
 * visitor. Returning anything that depends on the current cookie, session, or user
 * would leak one visitor's data to all. The PageContext this method receives
 * carries no per-visitor state — keep it that way (do not reach into $_COOKIE /
 * $_SESSION / the current user). Keep the payload small ("a handful, not a feed"):
 * it is stored in the page cache.
 */
interface ViewDataContributor
{
    /** @return array<string,mixed> */
    public function data(PageContext $page): array;
}

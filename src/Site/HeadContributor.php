<?php

declare(strict_types=1);

namespace Nimbus\Site;

/**
 * Contributes markup to the document <head> of a public page — structured data,
 * extra meta tags, and the like. Registered by a plugin through
 * PluginContext::head(); see ADR 0004.
 *
 * A contributor is handed the prepared PageContext and returns a string of HTML
 * (or '' for none). It returns markup, not layout — the theme owns how a page
 * *looks*; a contributor adds metadata that must work under any theme. It is
 * responsible for escaping any dynamic values it emits.
 */
interface HeadContributor
{
    public function head(PageContext $page): string;
}

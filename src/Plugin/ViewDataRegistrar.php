<?php

declare(strict_types=1);

namespace Nimbus\Plugin;

use Nimbus\Site\ViewDataContributor;
use Nimbus\Site\ViewDataContributorRegistry;

/**
 * The view-data-contribution capability, as a plugin sees it (ADR 0027).
 *
 * Narrower than the registry: a plugin can add a contributor and nothing else —
 * it cannot read or remove others'. The provider id is bound here by the loader,
 * not passed by the plugin, so a contribution cannot be attributed to (or rolled
 * back under) another plugin's name. Mirrors HeadRegistrar.
 */
final class ViewDataRegistrar
{
    public function __construct(
        private ViewDataContributorRegistry $registry,
        private string $pluginId,
    ) {
    }

    public function register(ViewDataContributor $contributor): void
    {
        $this->registry->add($contributor, $this->pluginId);
    }
}

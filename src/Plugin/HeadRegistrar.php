<?php

declare(strict_types=1);

namespace Nimbus\Plugin;

use Nimbus\Site\HeadContributor;
use Nimbus\Site\HeadContributorRegistry;

/**
 * The head-contribution capability, as a plugin sees it.
 *
 * Narrower than the registry: a plugin can add a contributor and nothing else —
 * it cannot read or remove others'. The provider id is bound here by the loader,
 * not passed by the plugin, so a contribution cannot be attributed to (or rolled
 * back under) another plugin's name. Mirrors FieldTypeRegistrar.
 */
final class HeadRegistrar
{
    public function __construct(
        private HeadContributorRegistry $registry,
        private string $pluginId,
    ) {
    }

    public function register(HeadContributor $contributor): void
    {
        $this->registry->add($contributor, $this->pluginId);
    }
}

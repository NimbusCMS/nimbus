<?php

declare(strict_types=1);

namespace Nimbus\Plugin;

use Nimbus\Auth\CapabilityRegistry;

/**
 * The capability-declaration surface, as a plugin sees it (ADR 0015, H2a).
 *
 * A plugin declares one grantable, wildcard-immune **management** capability —
 * its own — so an admin can grant `{pluginId}:read`/`:write` to a role and a
 * token can carry it, and the content `*:write` wildcard can never reach it. The
 * resource is this plugin's id, bound by the loader (not passed), so it cannot be
 * spoofed or made to shadow core. Mirrors EventRegistrar / FieldTypeRegistrar.
 *
 * See {@see CapabilityRegistry} for why the id must be namespaced and how that
 * closes every collision structurally.
 */
final class CapabilitiesRegistrar
{
    public function __construct(
        private CapabilityRegistry $registry,
        private string $pluginId,
    ) {
    }

    /**
     * Declare this plugin's management capability.
     *
     * @param string       $label   shown in the roles grant UI (e.g. "Inventory")
     * @param list<string> $actions a non-empty subset of {read, write}
     *
     * @throws \InvalidArgumentException on a flat plugin id, a bad label/actions,
     *                                   or a second declaration — each fails the load.
     */
    public function declare(string $label, array $actions): void
    {
        $this->registry->declare($this->pluginId, $label, $actions);
    }
}

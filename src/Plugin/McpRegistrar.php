<?php

declare(strict_types=1);

namespace Nimbus\Plugin;

use InvalidArgumentException;
use Nimbus\Mcp\McpToolsetRegistry;
use Nimbus\Mcp\PluginToolset;

/**
 * The MCP-toolset capability, as a plugin sees it (ADR 0016, H2b).
 *
 * A plugin registers one {@see PluginToolset} — its agent-facing tools. The
 * registrar binds this plugin's loader-verified id to the toolset (so its tools
 * gate on this plugin's capability, ADR 0015, and cannot masquerade as another's)
 * and validates its namespace. Mirrors the other registrars.
 */
final class McpRegistrar
{
    public function __construct(
        private McpToolsetRegistry $registry,
        private string $pluginId,
    ) {
    }

    /**
     * Register this plugin's toolset. Its `namespace()` must be `[a-z][a-z0-9]*`
     * and not already claimed by another plugin; either fails the plugin's load.
     *
     * @throws InvalidArgumentException on a malformed or duplicate namespace
     */
    public function register(PluginToolset $toolset): void
    {
        $namespace = $toolset->namespace();
        if (preg_match('/^[a-z][a-z0-9]*$/', $namespace) !== 1) {
            throw new InvalidArgumentException(
                "An MCP toolset namespace must be a lowercase token starting with a letter (e.g. \"inventory\"): \"{$namespace}\".",
            );
        }
        $toolset->bindTo($this->pluginId);
        $this->registry->add($namespace, $toolset, $this->pluginId);
    }
}

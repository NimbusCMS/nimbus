<?php

declare(strict_types=1);

namespace Nimbus\Mcp;

/**
 * The plugin-registered MCP toolsets (ADR 0016, H2b), composed into the one
 * {@see McpServer} after the core toolsets — so a fixed core tool name is always
 * claimed by core, never a plugin. Keyed by namespace, which is therefore unique:
 * a second plugin claiming a namespace another holds fails its load.
 */
final class McpToolsetRegistry
{
    /** @var array<string,array{toolset:PluginToolset,provider:string}> keyed by namespace */
    private array $toolsets = [];

    /**
     * Register a plugin toolset under its (already shape-validated) namespace.
     *
     * @throws \InvalidArgumentException if the namespace is already claimed
     */
    public function add(string $namespace, PluginToolset $toolset, string $provider): void
    {
        if (isset($this->toolsets[$namespace])) {
            throw new \InvalidArgumentException("MCP toolset namespace \"{$namespace}\" is already registered by another plugin.");
        }
        $this->toolsets[$namespace] = ['toolset' => $toolset, 'provider' => $provider];
    }

    /**
     * The registered toolsets, in registration order — appended to the server
     * after the core toolsets.
     *
     * @return list<PluginToolset>
     */
    public function all(): array
    {
        return array_values(array_map(static fn (array $e): PluginToolset => $e['toolset'], $this->toolsets));
    }

    /** Remove a provider's toolset — used on plugin-load rollback. */
    public function forgetProvider(string $provider): void
    {
        $this->toolsets = array_filter(
            $this->toolsets,
            static fn (array $e): bool => $e['provider'] !== $provider,
        );
    }
}

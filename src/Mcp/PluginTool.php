<?php

declare(strict_types=1);

namespace Nimbus\Mcp;

use Nimbus\Api\EntryOpContext;
use Nimbus\Api\TokenPrincipal;

/**
 * One tool a plugin exposes over MCP (ADR 0016, H2b), declared to
 * {@see PluginToolset}. The plugin writes the handler and the schema; the base
 * class owns the capability gate and the name-spacing, so a plugin author cannot
 * ship an ungated tool.
 *
 * `action` is the capability action the tool needs — conventionally `read` or
 * `write`, but any action the plugin declared ({@see \Nimbus\Auth\CapabilityRegistry},
 * ADR 0015/0030) — checked against the plugin's own management capability: a
 * `write` tool requires `{pluginId}:write`, a `kitchen` tool `{pluginId}:kitchen`,
 * each unreachable by the content wildcard.
 */
final readonly class PluginTool
{
    /**
     * @param string              $name        the local tool name (e.g. `receive`); the toolset
     *                                          advertises it namespaced (`inventory_receive`)
     * @param string              $action      the capability action this tool needs
     *                                          (`read`/`write`, or a plugin's own declared action)
     * @param array<string,mixed> $inputSchema JSON Schema for the arguments object
     * @param \Closure(array<string,mixed>, TokenPrincipal, EntryOpContext): array<string,mixed> $handler
     */
    public function __construct(
        public string $name,
        public string $action,
        public string $description,
        public array $inputSchema,
        public \Closure $handler,
    ) {
    }
}

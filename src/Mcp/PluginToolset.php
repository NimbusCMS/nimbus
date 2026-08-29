<?php

declare(strict_types=1);

namespace Nimbus\Mcp;

use Nimbus\Api\EntryOpContext;
use Nimbus\Api\TokenPrincipal;

/**
 * The base a plugin extends to expose MCP tools (ADR 0016, H2b) — the plugin-side
 * mirror of a core toolset, and the reason a plugin cannot ship an ungated tool.
 *
 * A plugin subclass declares only what its tools *are* — a `namespace()` (the
 * tool-name prefix) and `tools()` (name, action, schema, handler). This base owns
 * the two controls the security review required be structural, not trusted to the
 * author:
 *
 * 1. **Every tool gates.** Both `definitions()` (what a token may see) and
 *    `call()` (what it may run) check `principal->can({pluginId}, action)` against
 *    the plugin's own management capability (ADR 0015) — so a `write` tool is
 *    unreachable by the content `*:write` wildcard, and a token without the
 *    capability cannot even enumerate the tool (a denied name reports as unknown,
 *    matching core's non-enumeration). The author writes no gate; it cannot be
 *    forgotten.
 * 2. **Every name is namespaced.** A tool declared `receive` is advertised and
 *    dispatched as `{namespace}_receive`, so a plugin cannot collide with a peer
 *    (namespaces are unique — the registrar enforces it) and core tool names,
 *    composed first, always win a tie regardless.
 *
 * The `pluginId` — the capability resource — is **bound by the registrar** from
 * the loader-verified id, never chosen by the plugin, so a toolset cannot gate on
 * (or masquerade as) another plugin's capability.
 */
abstract class PluginToolset implements Toolset
{
    /** The capability resource (= plugin id) the registrar bound; '' until bound. */
    private string $boundPluginId = '';

    /**
     * The tool-name prefix — a short, distinctive token, `[a-z][a-z0-9]*`, ideally
     * the plugin's product name (e.g. `inventory`). The registrar validates its
     * shape and that no other plugin toolset claims it.
     */
    abstract public function namespace(): string;

    /**
     * This plugin's tools. Called per request (cheap; no state), so a subclass may
     * compute the list — but the shape is fixed for a given install.
     *
     * @return list<PluginTool>
     */
    abstract protected function tools(): array;

    /**
     * Bind the loader-verified plugin id (the capability resource). Called once by
     * {@see \Nimbus\Plugin\McpRegistrar}, never by the plugin.
     */
    final public function bindTo(string $pluginId): void
    {
        $this->boundPluginId = $pluginId;
    }

    final public function definitions(TokenPrincipal $principal): array
    {
        $defs = [];
        foreach ($this->tools() as $tool) {
            if ($principal->can($this->boundPluginId, $tool->action)) {
                $defs[] = [
                    'name'        => $this->qualify($tool->name),
                    'description' => $tool->description,
                    'inputSchema' => $tool->inputSchema,
                ];
            }
        }
        return $defs;
    }

    final public function call(string $name, array $args, TokenPrincipal $principal, EntryOpContext $ctx): ?array
    {
        foreach ($this->tools() as $tool) {
            if ($this->qualify($tool->name) !== $name) {
                continue;
            }
            if (!$principal->can($this->boundPluginId, $tool->action)) {
                // Non-enumeration parity with core: a name the token may not use is
                // reported as unknown, not "forbidden".
                throw new McpError(JsonRpc::INVALID_PARAMS, "Unknown tool \"{$name}\".");
            }
            return ($tool->handler)($args, $principal, $ctx);
        }
        return null; // not one of ours — defer to the next toolset
    }

    private function qualify(string $localName): string
    {
        return $this->namespace() . '_' . $localName;
    }
}

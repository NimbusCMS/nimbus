<?php

declare(strict_types=1);

namespace Nimbus\Mcp;

use Nimbus\Api\EntryOpContext;
use Nimbus\Api\TokenPrincipal;

/**
 * A group of MCP tools (ADR 0009). The server composes several — content, then
 * the management groups (schema, media, users/tokens/settings) — so each slice
 * adds a toolset without touching the others.
 *
 * Two responsibilities: advertise the tools a token may see (scope-filtered),
 * and execute a call it owns. `call()` returns null when the tool name is not in
 * this toolset's domain, so the server can offer it to the next toolset; a name
 * it *does* own but the token may not use is reported as an unknown tool (via
 * {@see McpError}) rather than "forbidden", keeping discovery non-enumerating.
 *
 * Ordering matters: management toolsets are composed *before* the content
 * toolset, so a fixed management name (e.g. `create_collection`) is claimed as
 * management and never mis-parsed as a content verb on a collection.
 */
interface Toolset
{
    /**
     * The tool definitions this token may see.
     *
     * @return list<array<string,mixed>>
     */
    public function definitions(TokenPrincipal $principal): array;

    /**
     * Execute the named call, or return null if this toolset does not own it.
     *
     * @param array<string,mixed> $args
     * @return array<string,mixed>|null the tool result, or null to defer
     */
    public function call(string $name, array $args, TokenPrincipal $principal, EntryOpContext $ctx): ?array;
}

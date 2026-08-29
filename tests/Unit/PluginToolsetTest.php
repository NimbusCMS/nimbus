<?php

declare(strict_types=1);

namespace Nimbus\Tests\Unit;

use Nimbus\Api\EntryOpContext;
use Nimbus\Api\TokenPrincipal;
use Nimbus\Mcp\McpError;
use Nimbus\Mcp\PluginTool;
use Nimbus\Mcp\PluginToolset;
use PHPUnit\Framework\TestCase;

/** A plugin toolset for the tests: one read tool, one write tool. */
final class FixtureToolset extends PluginToolset
{
    public function namespace(): string
    {
        return 'fixture';
    }

    protected function tools(): array
    {
        return [
            new PluginTool('read_thing', 'read', 'Read a thing', ['type' => 'object'], static fn (array $a, TokenPrincipal $p, EntryOpContext $c): array => ['did' => 'read']),
            new PluginTool('write_thing', 'write', 'Write a thing', ['type' => 'object'], static fn (array $a, TokenPrincipal $p, EntryOpContext $c): array => ['did' => 'write']),
        ];
    }
}

/**
 * The base every plugin toolset extends (ADR 0016, H2b). It owns the two controls
 * the security review required be structural: every tool gates on the plugin's
 * capability, and every name is namespaced. The plugin author writes neither.
 */
final class PluginToolsetTest extends TestCase
{
    private EntryOpContext $ctx;

    protected function setUp(): void
    {
        $this->ctx = new EntryOpContext('127.0.0.1', '/api/v1/mcp');
    }

    private function toolset(): FixtureToolset
    {
        $t = new FixtureToolset();
        $t->bindTo('nimbuscms.fixture'); // the registrar binds the loader-verified id
        return $t;
    }

    private function principal(string ...$scopes): TokenPrincipal
    {
        return new TokenPrincipal(1, 'test', array_values($scopes));
    }

    public function test_tool_names_are_namespaced(): void
    {
        $names = array_column($this->toolset()->definitions($this->principal('nimbuscms.fixture:read', 'nimbuscms.fixture:write')), 'name');
        self::assertSame(['fixture_read_thing', 'fixture_write_thing'], $names, 'declared local names are advertised under the namespace');
    }

    public function test_definitions_are_scope_filtered_per_tool(): void
    {
        // A read-only token sees only the read tool — the write tool is invisible,
        // not just un-callable (non-enumeration).
        $names = array_column($this->toolset()->definitions($this->principal('nimbuscms.fixture:read')), 'name');
        self::assertSame(['fixture_read_thing'], $names);
    }

    public function test_a_token_without_the_capability_sees_nothing(): void
    {
        self::assertSame([], $this->toolset()->definitions($this->principal()), 'no capability, no tools advertised');
        self::assertSame([], $this->toolset()->definitions($this->principal('posts:write')), 'a content scope is not the plugin capability');
    }

    public function test_a_granted_tool_runs(): void
    {
        self::assertSame(['did' => 'write'], $this->toolset()->call('fixture_write_thing', [], $this->principal('nimbuscms.fixture:write'), $this->ctx));
        self::assertSame(['did' => 'read'], $this->toolset()->call('fixture_read_thing', [], $this->principal('nimbuscms.fixture:read'), $this->ctx));
    }

    public function test_a_write_tool_is_denied_to_a_read_only_token_as_unknown(): void
    {
        // The gate the plugin author cannot forget — and non-enumeration: denied
        // reports as "unknown tool", not "forbidden".
        $this->expectException(McpError::class);
        $this->expectExceptionMessage('Unknown tool "fixture_write_thing"');
        $this->toolset()->call('fixture_write_thing', [], $this->principal('nimbuscms.fixture:read'), $this->ctx);
    }

    public function test_a_tool_this_toolset_does_not_own_defers(): void
    {
        // Returning null lets the server offer the name to the next toolset — and a
        // bare (un-namespaced) local name is not owned.
        self::assertNull($this->toolset()->call('create_collection', [], $this->principal('nimbuscms.fixture:write'), $this->ctx), 'a core name is not ours');
        self::assertNull($this->toolset()->call('read_thing', [], $this->principal('nimbuscms.fixture:write'), $this->ctx), 'the un-namespaced local name is not owned');
    }
}

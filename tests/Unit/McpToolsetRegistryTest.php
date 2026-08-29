<?php

declare(strict_types=1);

namespace Nimbus\Tests\Unit;

use Nimbus\Mcp\McpToolsetRegistry;
use Nimbus\Mcp\PluginToolset;
use Nimbus\Plugin\McpRegistrar;
use PHPUnit\Framework\TestCase;

/** A toolset with a caller-chosen namespace, for the shape-validation cases. */
final class NamespacedToolset extends PluginToolset
{
    public function __construct(private string $ns)
    {
    }

    public function namespace(): string
    {
        return $this->ns;
    }

    protected function tools(): array
    {
        return [];
    }
}

/**
 * The plugin-toolset registry + registrar (ADR 0016, H2b): namespace uniqueness
 * and shape are enforced at registration, so a collision fails the plugin's load.
 */
final class McpToolsetRegistryTest extends TestCase
{
    public function test_a_registered_toolset_is_bound_and_listed(): void
    {
        $registry = new McpToolsetRegistry();
        (new McpRegistrar($registry, 'nimbuscms.fixture'))->register(new FixtureToolset());

        self::assertCount(1, $registry->all());
    }

    public function test_two_plugins_cannot_claim_the_same_namespace(): void
    {
        $registry = new McpToolsetRegistry();
        (new McpRegistrar($registry, 'nimbuscms.fixture'))->register(new FixtureToolset());

        $this->expectException(\InvalidArgumentException::class);
        // A different plugin, same namespace ("fixture") — must fail its load.
        (new McpRegistrar($registry, 'acme.other'))->register(new FixtureToolset());
    }

    public function test_forget_provider_removes_only_that_plugins_toolset(): void
    {
        $registry = new McpToolsetRegistry();
        (new McpRegistrar($registry, 'nimbuscms.fixture'))->register(new FixtureToolset());
        $registry->forgetProvider('nimbuscms.fixture');

        self::assertSame([], $registry->all());
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badNamespaces')]
    public function test_a_malformed_namespace_is_refused(string $namespace): void
    {
        $registry = new McpToolsetRegistry();
        $this->expectException(\InvalidArgumentException::class);
        (new McpRegistrar($registry, 'nimbuscms.fixture'))->register(new NamespacedToolset($namespace));
    }

    /** @return array<string,array{string}> */
    public static function badNamespaces(): array
    {
        return [
            'empty'          => [''],
            'leading digit'  => ['9inventory'],
            'underscore'     => ['my_tools'],
            'uppercase'      => ['Inventory'],
            'dot'            => ['nimbuscms.inventory'],
            'hyphen'         => ['inv-tools'],
        ];
    }
}

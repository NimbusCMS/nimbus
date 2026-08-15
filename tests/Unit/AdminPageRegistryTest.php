<?php

declare(strict_types=1);

namespace Nimbus\Tests\Unit;

use InvalidArgumentException;
use Nimbus\Admin\AdminPageRegistry;
use Nimbus\Plugin\AdminPageRegistrar;
use Nimbus\Plugin\PluginCapabilities;
use Nimbus\Plugin\PluginContext;
use PHPUnit\Framework\TestCase;

final class AdminPageRegistryTest extends TestCase
{
    private function handler(): callable
    {
        return static fn (): string => 'x';
    }

    public function test_add_and_all_preserve_order(): void
    {
        $registry = new AdminPageRegistry();
        $registry->add('a', 'A', '★', $this->handler(), 'p1');
        $registry->add('b', 'B', '☆', $this->handler(), 'p2');

        self::assertSame(['a', 'b'], array_column($registry->all(), 'slug'));
    }

    public function test_forget_provider_removes_only_that_providers_pages(): void
    {
        $registry = new AdminPageRegistry();
        $registry->add('a', 'A', '★', $this->handler(), 'p1');
        $registry->add('b', 'B', '☆', $this->handler(), 'p2');

        $registry->forgetProvider('p1');

        self::assertSame(['b'], array_column($registry->all(), 'slug'));
    }

    public function test_the_registrar_accepts_a_valid_slug_and_binds_the_provider(): void
    {
        $registry = new AdminPageRegistry();
        (new AdminPageRegistrar($registry, 'nimbuscms.analytics'))
            ->register('analytics', 'Analytics', '📊', $this->handler());

        self::assertSame('analytics', $registry->all()[0]['slug']);
        self::assertSame('nimbuscms.analytics', $registry->all()[0]['provider']);
    }

    public function test_the_registrar_rejects_an_unsafe_slug(): void
    {
        $registrar = new AdminPageRegistrar(new AdminPageRegistry(), 'nimbuscms.analytics');

        $this->expectException(InvalidArgumentException::class);
        $registrar->register('Not A Slug!', 'x', 'x', $this->handler());
    }

    public function test_plugin_context_binds_pages_to_the_plugin_id(): void
    {
        $registry = new AdminPageRegistry();
        $context  = new PluginContext(new PluginCapabilities(adminPages: $registry), 'nimbuscms.analytics');

        $context->adminPages()->register('analytics', 'Analytics', '📊', $this->handler());
        self::assertSame('nimbuscms.analytics', $registry->all()[0]['provider']);

        $registry->forgetProvider('nimbuscms.analytics');
        self::assertSame([], $registry->all());
    }
}

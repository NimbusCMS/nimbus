<?php

declare(strict_types=1);

namespace Nimbus\Tests\Unit;

use Nimbus\Content\FieldTypeRegistry;
use Nimbus\Database\MigrationRegistry;
use Nimbus\Plugin\PluginContext;
use Nimbus\Site\HeadContributorRegistry;
use Nimbus\Support\EventDispatcher;
use PHPUnit\Framework\TestCase;

final class MigrationRegistryTest extends TestCase
{
    public function test_add_and_all_preserve_registration_order(): void
    {
        $registry = new MigrationRegistry();
        $registry->add('a', ['SQL A'], 'p1');
        $registry->add('b', ['SQL B'], 'p2');

        self::assertSame(['a', 'b'], array_column($registry->all(), 'name'));
    }

    public function test_forget_provider_removes_only_that_providers_migrations(): void
    {
        $registry = new MigrationRegistry();
        $registry->add('a', ['x'], 'p1');
        $registry->add('b', ['y'], 'p2');

        $registry->forgetProvider('p1');

        self::assertSame(['b'], array_column($registry->all(), 'name'));
    }

    public function test_the_registrar_namespaces_the_name_and_binds_the_provider(): void
    {
        $registry = new MigrationRegistry();
        $context  = new PluginContext(
            new FieldTypeRegistry(),
            new HeadContributorRegistry(),
            new EventDispatcher(),
            $registry,
            'nimbuscms.analytics',
        );

        $context->migrations()->register('001_hits', ['CREATE TABLE …']);

        self::assertSame('nimbuscms.analytics:001_hits', $registry->all()[0]['name']);

        // Rolling back the plugin id drops its migration — proves the binding.
        $registry->forgetProvider('nimbuscms.analytics');
        self::assertSame([], $registry->all());
    }
}

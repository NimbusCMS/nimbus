<?php

declare(strict_types=1);

namespace Nimbus\Tests\Unit;

use Nimbus\Support\MaintenanceRegistry;
use PHPUnit\Framework\TestCase;

final class MaintenanceRegistryTest extends TestCase
{
    public function test_it_collects_tasks_in_order_with_their_provider(): void
    {
        $registry = new MaintenanceRegistry();
        $registry->add('a:prune', static fn (): int => 3, 'a');
        $registry->add('b:prune', static fn (): int => 0, 'b');

        $all = $registry->all();
        self::assertSame(['a:prune', 'b:prune'], array_column($all, 'name'));
        self::assertSame(3, ($all[0]['task'])(), 'the task is runnable and returns its affected count');
    }

    public function test_forget_provider_drops_only_that_providers_tasks(): void
    {
        $registry = new MaintenanceRegistry();
        $registry->add('a:x', static fn (): int => 0, 'a');
        $registry->add('b:y', static fn (): int => 0, 'b');

        $registry->forgetProvider('a');

        self::assertSame(['b:y'], array_column($registry->all(), 'name'));
    }
}

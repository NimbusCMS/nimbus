<?php

declare(strict_types=1);

namespace Nimbus\Tests\Unit;

use Nimbus\Plugin\PluginCapabilities;
use Nimbus\Plugin\PluginContext;
use Nimbus\Support\EventDispatcher;
use PHPUnit\Framework\TestCase;

final class EventDispatcherTest extends TestCase
{
    public function test_listeners_fire_in_order_with_the_payload(): void
    {
        $dispatcher = new EventDispatcher();
        $seen       = [];
        $dispatcher->listen('x', function (mixed $p) use (&$seen): void {
            $seen[] = 'a' . $p;
        });
        $dispatcher->listen('x', function (mixed $p) use (&$seen): void {
            $seen[] = 'b' . $p;
        });

        $dispatcher->dispatch('x', '1');

        self::assertSame(['a1', 'b1'], $seen);
    }

    public function test_has_listeners(): void
    {
        $dispatcher = new EventDispatcher();
        self::assertFalse($dispatcher->hasListeners('x'));

        $dispatcher->listen('x', static fn (): null => null);
        self::assertTrue($dispatcher->hasListeners('x'));
    }

    public function test_forget_provider_removes_only_that_providers_listeners(): void
    {
        $dispatcher = new EventDispatcher();
        $seen       = [];
        $dispatcher->listen('x', function () use (&$seen): void {
            $seen[] = 'core';
        }); // no provider
        $dispatcher->listen('x', function () use (&$seen): void {
            $seen[] = 'p1';
        }, 'p1');
        $dispatcher->listen('x', function () use (&$seen): void {
            $seen[] = 'p2';
        }, 'p2');

        $dispatcher->forgetProvider('p1');
        $dispatcher->dispatch('x');

        self::assertSame(['core', 'p2'], $seen, 'a core listener and the other plugin survive');
    }

    public function test_plugin_context_binds_listeners_to_the_plugin_id(): void
    {
        $dispatcher = new EventDispatcher();
        $context    = new PluginContext(new PluginCapabilities(events: $dispatcher), 'nimbuscms.analytics');

        $fired = 0;
        $context->events()->listen('x', function () use (&$fired): void {
            $fired++;
        });
        $dispatcher->dispatch('x');
        self::assertSame(1, $fired);

        // Rolling back that plugin id removes its listener — proves the binding.
        $dispatcher->forgetProvider('nimbuscms.analytics');
        $dispatcher->dispatch('x');
        self::assertSame(1, $fired, 'the rolled-back plugin listener does not fire again');
    }
}

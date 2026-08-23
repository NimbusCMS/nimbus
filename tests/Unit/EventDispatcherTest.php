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

    public function test_emit_best_effort_isolates_each_listener(): void
    {
        // SUP-3 / PLUG-3: a throwing listener must not starve the ones after it
        // (e.g. the audit-log listener). Red on the pre-fix whole-loop catch.
        $dispatcher = new EventDispatcher();
        $ran        = [];
        $dispatcher->listen('api.management_written', static function (): void {
            throw new \RuntimeException('buggy plugin A');
        }, 'plugin.a');
        $dispatcher->listen('api.management_written', function () use (&$ran): void {
            $ran[] = 'audit'; // the later listener still records
        }, 'plugin.b');

        $dispatcher->emitBestEffort('api.management_written', ['x' => 1]);

        self::assertSame(['audit'], $ran, 'the later listener still ran despite the earlier throw');
    }

    public function test_emit_best_effort_never_propagates(): void
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->listen('e', static function (): void {
            throw new \RuntimeException('boom');
        });

        $dispatcher->emitBestEffort('e', null); // must not throw
        $this->addToAssertionCount(1);
    }

    public function test_dispatch_still_propagates_for_post_commit_events(): void
    {
        // The deliberate asymmetry: entry.* events go through dispatch(), whose
        // listeners are allowed to matter and must surface (Slice D's lesson —
        // don't let a "make everything safe" refactor silence these).
        $dispatcher = new EventDispatcher();
        $dispatcher->listen('entry.saved', static function (): void {
            throw new \RuntimeException('a listener that must be heard');
        });

        $this->expectException(\RuntimeException::class);
        $dispatcher->dispatch('entry.saved', null);
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

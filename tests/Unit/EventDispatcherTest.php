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

    public function test_plugin_emit_namespaces_under_the_plugin_id_verbatim(): void
    {
        // H1 (ADR 0014): a plugin emits under its id *verbatim* — never a stripped
        // `low` (two `*.inventory` plugins would collide) — so a listener on the
        // fully-qualified name hears it and a bare-name listener does not.
        $dispatcher = new EventDispatcher();
        $context    = new PluginContext(new PluginCapabilities(events: $dispatcher), 'nimbuscms.inventory');

        $qualified = 0;
        $bare      = 0;
        $seen      = null;
        $dispatcher->listen('nimbuscms.inventory.low', function (mixed $p, string $e) use (&$qualified, &$seen): void {
            $qualified++;
            $seen = $e;
        });
        $dispatcher->listen('low', function () use (&$bare): void {
            $bare++;
        });

        $context->events()->emit('low', ['sku' => 'ABC']);

        self::assertSame(1, $qualified, 'the fully-qualified listener heard it');
        self::assertSame(0, $bare, 'a bare-name listener is a different, un-namespaced event');
        self::assertSame('nimbuscms.inventory.low', $seen, 'the event name carries the plugin id verbatim');
    }

    public function test_plugin_emit_is_best_effort_and_does_not_fail_the_emitter(): void
    {
        // A subscriber throwing (an `inventory.low` notifier) must never surface to
        // 500 the stock write that emitted — emit routes through emitBestEffort.
        $dispatcher = new EventDispatcher();
        $context    = new PluginContext(new PluginCapabilities(events: $dispatcher), 'nimbuscms.inventory');
        $dispatcher->listen('nimbuscms.inventory.low', static function (): void {
            throw new \RuntimeException('a buggy subscriber');
        });

        $context->events()->emit('low'); // must not throw
        $this->addToAssertionCount(1);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('malformedEmitNames')]
    public function test_plugin_emit_rejects_a_malformed_local_name(string $bad): void
    {
        // The emitter's own programming error — surfaced loudly, unlike a listener
        // failure. Guards the namespace from `pluginId.` and stray characters.
        $dispatcher = new EventDispatcher();
        $context    = new PluginContext(new PluginCapabilities(events: $dispatcher), 'nimbuscms.inventory');

        $this->expectException(\InvalidArgumentException::class);
        $context->events()->emit($bad);
    }

    /** @return array<string,array{string}> */
    public static function malformedEmitNames(): array
    {
        return [
            'empty'        => [''],
            'uppercase'    => ['Low'],
            'leading dot'  => ['.low'],
            'trailing dot' => ['low.'],
            'double dot'   => ['stock..low'],
            'space'        => ['stock low'],
            'colon'        => ['stock:low'],
        ];
    }

    public function test_dispatch_is_bounded_when_a_listener_re_dispatches_forever(): void
    {
        // A listener loop (`a` re-dispatching `a`) must be capped, not overflow the
        // stack. It runs up to the ceiling, then the next delivery is dropped.
        $dispatcher = new EventDispatcher();
        $runs       = 0;
        $dispatcher->listen('loop', function () use (&$runs, $dispatcher): void {
            $runs++;
            $dispatcher->dispatch('loop');
        });

        $dispatcher->dispatch('loop');

        self::assertSame(8, $runs, 'delivery is capped at EventDispatcher::MAX_DEPTH, not infinite');
    }

    public function test_best_effort_is_bounded_when_a_listener_re_emits_forever(): void
    {
        $dispatcher = new EventDispatcher();
        $runs       = 0;
        $dispatcher->listen('loop', function () use (&$runs, $dispatcher): void {
            $runs++;
            $dispatcher->emitBestEffort('loop');
        });

        $dispatcher->emitBestEffort('loop');

        self::assertSame(8, $runs, 'best-effort delivery is capped at EventDispatcher::MAX_DEPTH');
    }

    public function test_depth_unwinds_so_later_events_are_not_starved(): void
    {
        // A throwing post-commit listener must not leave the depth counter raised
        // and silently cap every subsequent event in the same request.
        $dispatcher = new EventDispatcher();
        $dispatcher->listen('entry.saved', static function (): void {
            throw new \RuntimeException('heard, and it threw');
        });
        try {
            $dispatcher->dispatch('entry.saved');
        } catch (\RuntimeException) {
            // expected — dispatch propagates
        }

        $later = 0;
        $dispatcher->listen('after', function () use (&$later): void {
            $later++;
        });
        $dispatcher->dispatch('after');

        self::assertSame(1, $later, 'the counter unwound after the throw; later events still deliver');
    }
}

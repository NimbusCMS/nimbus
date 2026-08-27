<?php

declare(strict_types=1);

namespace Nimbus\Plugin;

use Nimbus\Support\EventDispatcher;

/**
 * The event capability, as a plugin sees it (ADR 0014).
 *
 * A plugin can subscribe to any event and emit under **its own namespace** — it
 * cannot dispatch into another namespace or read others' listeners. Every
 * registration is stamped with this plugin's id (bound by the loader, not the
 * plugin), so it rolls back with the plugin's other registrations if its load
 * fails. Mirrors FieldTypeRegistrar / HeadRegistrar.
 *
 * See CoreEvents for what core dispatches and each event's delivery semantics —
 * notably that request.handled is best-effort and post-response.
 */
final class EventRegistrar
{
    public function __construct(
        private EventDispatcher $events,
        private string $pluginId,
    ) {
    }

    /** @param callable(mixed,string):void $listener */
    public function listen(string $event, callable $listener): void
    {
        $this->events->listen($event, $listener, $this->pluginId);
    }

    /**
     * Emit an event from this plugin. The name is **always** namespaced under the
     * plugin's id verbatim — `emit('low')` from plugin `nimbuscms.inventory`
     * dispatches `nimbuscms.inventory.low`, never a bare `low` (two plugins would
     * collide) and never into a core namespace (the loader forbids an id rooted in
     * one, so the prefix is proven safe — {@see \Nimbus\Support\CoreEvents::reservedRoots()}).
     *
     * Delivery is **best-effort and depth-bounded**: a throwing listener is
     * isolated and logged, never surfacing to fail the plugin operation that
     * emitted (an `inventory.low` subscriber must not 500 the stock write that
     * triggered it), and a listener loop is capped rather than allowed to recurse.
     * Emitting is fire-and-forget — a plugin cannot learn who listened or veto
     * anything, matching core's post-commit event contract.
     *
     * `$name` is the local name: one or more lowercase dot-separated segments
     * (`low`, `stock.recounted`). A malformed name is the emitting plugin's own
     * programming error — surfaced loudly here, distinct from a listener failure.
     *
     * @throws \InvalidArgumentException if `$name` is not a well-formed local name
     */
    public function emit(string $name, mixed $payload = null): void
    {
        if (preg_match('/^[a-z0-9]+(\.[a-z0-9]+)*$/', $name) !== 1) {
            throw new \InvalidArgumentException(
                "Event name \"{$name}\" must be one or more lowercase, dot-separated alphanumeric segments (e.g. \"stock.recounted\"). "
                . 'It is namespaced under the plugin id automatically — do not include it.',
            );
        }
        $this->events->emitBestEffort($this->pluginId . '.' . $name, $payload);
    }
}

<?php

declare(strict_types=1);

namespace Nimbus\Plugin;

use Nimbus\Support\EventDispatcher;

/**
 * The event-subscription capability, as a plugin sees it.
 *
 * A plugin can add a listener and nothing else — it cannot dispatch events or
 * read others' listeners. Every registration is stamped with this plugin's id
 * (bound by the loader, not the plugin), so it rolls back with the plugin's
 * other registrations if its load fails. Mirrors FieldTypeRegistrar / HeadRegistrar.
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
}

<?php

declare(strict_types=1);

namespace Nimbus\Support;

/**
 * A minimal synchronous event bus — the seam plugins and features hook into
 * without touching the core. Revisions, the activity log and (later) webhooks
 * all subscribe here rather than being wired into the entry save path.
 *
 *   $events->listen(CoreEvents::ENTRY_SAVED, fn ($e) => ...);
 *   $events->dispatch(CoreEvents::ENTRY_SAVED, $entry);
 *
 * Deliberately an instance, not a static registry. The application composes one
 * dispatcher and hands that instance to whatever needs it. Static listeners
 * survive across everything in the same process, which means tests leak into
 * each other, two application instances silently share behaviour, and a double
 * bootstrap registers every listener twice. Plugin loading makes all three
 * likely rather than theoretical.
 *
 * See CoreEvents for the semantics of the events core dispatches.
 */
final class EventDispatcher
{
    /** @var array<string,list<callable>> */
    private array $listeners = [];

    public function listen(string $event, callable $listener): void
    {
        $this->listeners[$event][] = $listener;
    }

    public function dispatch(string $event, mixed $payload = null): void
    {
        foreach ($this->listeners[$event] ?? [] as $listener) {
            $listener($payload, $event);
        }
    }

    /** Whether anything is listening — useful to skip building an expensive payload. */
    public function hasListeners(string $event): bool
    {
        return ($this->listeners[$event] ?? []) !== [];
    }
}

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
    /** @var array<string,list<array{listener:callable,provider:?string}>> */
    private array $listeners = [];

    /**
     * Register a listener. A `$provider` (a plugin id) tags the registration so
     * it can be rolled back if that plugin's load fails; core listeners pass none.
     */
    public function listen(string $event, callable $listener, ?string $provider = null): void
    {
        $this->listeners[$event][] = ['listener' => $listener, 'provider' => $provider];
    }

    public function dispatch(string $event, mixed $payload = null): void
    {
        foreach ($this->listeners[$event] ?? [] as $entry) {
            ($entry['listener'])($payload, $event);
        }
    }

    /** Whether anything is listening — useful to skip building an expensive payload. */
    public function hasListeners(string $event): bool
    {
        return ($this->listeners[$event] ?? []) !== [];
    }

    /**
     * Fire an observation event that must never affect the caller: nothing is
     * dispatched when no one is listening, and a listener that throws is caught
     * and logged rather than propagated. For best-effort events like
     * request.handled and the api.* failure events — not for post-commit entry
     * events, whose listeners are allowed to matter.
     */
    public function emitBestEffort(string $event, mixed $payload = null): void
    {
        if (!$this->hasListeners($event)) {
            return;
        }
        try {
            $this->dispatch($event, $payload);
        } catch (\Throwable $e) {
            error_log("[nimbus {$event}] " . $e);
        }
    }

    /** Remove every listener a provider registered — used on plugin-load rollback. */
    public function forgetProvider(string $provider): void
    {
        foreach ($this->listeners as $event => $entries) {
            $this->listeners[$event] = array_values(array_filter(
                $entries,
                static fn (array $entry): bool => $entry['provider'] !== $provider,
            ));
        }
    }
}

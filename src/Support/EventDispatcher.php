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
     * How deeply we are currently nested in delivery. A listener is allowed to
     * dispatch/emit again (legitimate fan-out — an `entry.saved` listener that
     * emits `inventory.recount`), so this counts the nesting across *every*
     * delivery path, not per-event.
     */
    private int $depth = 0;

    /**
     * The re-entrancy ceiling. A few levels of fan-out is normal; past this is a
     * runaway loop — `a` emits `b`, whose listener emits `a`, forever — that would
     * otherwise overflow the stack or hang the request. At the ceiling the *next*
     * delivery is dropped (logged), breaking the cycle without unwinding the work
     * already done. 8 is comfortably above any real chain and well below PHP's
     * default stack limits. This became reachable when plugins gained the ability
     * to emit (ADR 0014); before that, only core dispatched, at controlled points.
     */
    private const MAX_DEPTH = 8;

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
        if ($this->depth >= self::MAX_DEPTH) {
            error_log("[nimbus {$event}] dropped: event re-entrancy depth (" . self::MAX_DEPTH . ') reached — a listener loop is likely');
            return;
        }
        $this->depth++;
        try {
            foreach ($this->listeners[$event] ?? [] as $entry) {
                ($entry['listener'])($payload, $event);
            }
        } finally {
            // Even when a listener throws (dispatch propagates, by design), the
            // depth must unwind, or one failed post-commit event poisons the cap
            // for the rest of the request.
            $this->depth--;
        }
    }

    /** Whether anything is listening — useful to skip building an expensive payload. */
    public function hasListeners(string $event): bool
    {
        return ($this->listeners[$event] ?? []) !== [];
    }

    /**
     * Fire an observation event that must never affect the caller — and never let
     * one listener starve another. Each listener runs in its own try/catch: a
     * throw is logged (with the provider id, so the operator knows which plugin)
     * and delivery **continues** to the rest. For best-effort events like
     * request.handled and the api.* audit events — not for post-commit entry
     * events, whose listeners are allowed to matter and so go through
     * {@see dispatch()}, which propagates.
     */
    public function emitBestEffort(string $event, mixed $payload = null): void
    {
        if ($this->depth >= self::MAX_DEPTH) {
            error_log("[nimbus {$event}] dropped: event re-entrancy depth (" . self::MAX_DEPTH . ') reached — a listener loop is likely');
            return;
        }
        $this->depth++;
        try {
            foreach ($this->listeners[$event] ?? [] as $entry) {
                try {
                    ($entry['listener'])($payload, $event);
                } catch (\Throwable $e) {
                    // Isolated per listener: one buggy plugin can't blind another's
                    // audit/analytics record for the same event.
                    error_log("[nimbus {$event}] " . ($entry['provider'] ?? 'core') . ': ' . $e);
                }
            }
        } finally {
            $this->depth--;
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

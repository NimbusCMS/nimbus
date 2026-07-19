<?php

declare(strict_types=1);

namespace Nimbus\Support;

/**
 * A minimal synchronous event bus — the seam plugins and features hook into
 * without touching the core. Revisions, the activity log and (later) webhooks
 * all subscribe here rather than being wired into the entry save path.
 *
 *   Events::listen('entry.saved', fn($e) => ...);
 *   Events::dispatch('entry.saved', $entry);
 */
final class Events
{
    /** @var array<string,array<int,callable>> */
    private static array $listeners = [];

    public static function listen(string $event, callable $listener): void
    {
        self::$listeners[$event][] = $listener;
    }

    public static function dispatch(string $event, mixed $payload = null): void
    {
        foreach (self::$listeners[$event] ?? [] as $listener) {
            $listener($payload, $event);
        }
    }

    /** For tests / isolation. */
    public static function reset(): void
    {
        self::$listeners = [];
    }
}

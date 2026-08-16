<?php

declare(strict_types=1);

namespace Nimbus\Support;

/**
 * Maintenance tasks plugins declare, collected at boot and run by `nimbus prune`
 * (from cron). Composed once and shared, like the other capability registries.
 *
 * A task keeps the plugin's *own* data tidy — most often deleting rows past a
 * retention window ([ADR 0005](../../../docs/adr/0005-plugin-owned-storage.md)):
 * it never touches core `nb_*` tables. Each task's name is prefixed with the
 * plugin id (bound by the registrar), so a failed load rolls its tasks back with
 * the rest of its registrations.
 */
final class MaintenanceRegistry
{
    /** @var list<array{name:string,task:callable,provider:string}> */
    private array $tasks = [];

    /** @param callable():int $task returns the number of rows/items it affected */
    public function add(string $name, callable $task, string $provider): void
    {
        $this->tasks[] = ['name' => $name, 'task' => $task, 'provider' => $provider];
    }

    /** @return list<array{name:string,task:callable,provider:string}> in registration order */
    public function all(): array
    {
        return $this->tasks;
    }

    /** Drop a provider's tasks — used when a plugin's load fails. */
    public function forgetProvider(string $provider): void
    {
        $this->tasks = array_values(array_filter(
            $this->tasks,
            static fn (array $task): bool => $task['provider'] !== $provider,
        ));
    }
}

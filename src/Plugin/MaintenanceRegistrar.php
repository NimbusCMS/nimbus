<?php

declare(strict_types=1);

namespace Nimbus\Plugin;

use Nimbus\Support\MaintenanceRegistry;

/**
 * The maintenance capability, as a plugin sees it.
 *
 * A plugin registers a task — run by `nimbus prune` — that keeps its *own* data
 * tidy, most often deleting rows past a retention window (ADR 0005). The task
 * returns how many rows/items it affected, for the command's report. Its name is
 * prefixed with the plugin id (bound by the loader), so a failed load rolls its
 * tasks back with the rest of its registrations. Mirrors the other registrars.
 */
final class MaintenanceRegistrar
{
    public function __construct(
        private MaintenanceRegistry $registry,
        private string $pluginId,
    ) {
    }

    /** @param callable():int $task returns the number of rows/items affected */
    public function register(string $name, callable $task): void
    {
        $this->registry->add($this->pluginId . ':' . $name, $task, $this->pluginId);
    }
}

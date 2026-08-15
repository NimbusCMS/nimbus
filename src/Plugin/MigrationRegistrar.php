<?php

declare(strict_types=1);

namespace Nimbus\Plugin;

use Nimbus\Database\MigrationRegistry;

/**
 * The migrations capability, as a plugin sees it.
 *
 * A plugin declares migrations that create and evolve its *own* tables — never
 * core `nb_*` tables ([ADR 0005](../../../docs/adr/0005-plugin-owned-storage.md)).
 * The name is prefixed with the plugin id here (bound by the loader), so it is
 * globally unique in `nb_migrations` and a failed load rolls its migrations back
 * with the rest of its registrations. Mirrors the other registrars.
 */
final class MigrationRegistrar
{
    public function __construct(
        private MigrationRegistry $registry,
        private string $pluginId,
    ) {
    }

    /**
     * Declare a migration: a name local to the plugin (e.g. `001_create_hits`)
     * and the SQL statements that apply it, run once and recorded.
     *
     * @param list<string> $statements
     */
    public function register(string $name, array $statements): void
    {
        $this->registry->add($this->pluginId . ':' . $name, $statements, $this->pluginId);
    }
}

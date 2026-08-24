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
 *
 * **Table names are yours to keep unique.** ADR 0005 asks each plugin to keep its
 * tables in its own namespace: **prefix every table you create with your plugin
 * slug** (e.g. `analytics_hits`, not `hits`), so two plugins can't collide on a
 * generic name and wedge `nimbus migrate`. Nimbus namespaces the migration *name*
 * for you, but not the SQL — the table names are whatever your statements say.
 * (This is a convention, not a sandbox: nothing stops a determined plugin from
 * naming a table anything — see the contract-not-sandbox note in ADR 0005 — but
 * following it keeps honest plugins from colliding by accident.)
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
     * **Make each statement individually idempotent** (`CREATE TABLE IF NOT
     * EXISTS`, `DROP … IF EXISTS`, a guarded `ADD COLUMN`). MySQL auto-commits DDL
     * and cannot roll it back, so if statement 2 fails, statement 1 stays applied
     * and the migration is **not** recorded — the runner isolates your plugin (it
     * won't wedge others or core) and will **retry** this whole migration on the
     * next `nimbus migrate`. A non-idempotent statement 1 then fails with "already
     * exists" and your plugin can never migrate.
     *
     * Consequently, **your runtime must not assume a table/column/constraint
     * exists until the migration is recorded** — a half-applied migration can
     * leave a table present but missing the UNIQUE/INDEX a later statement adds.
     *
     * @param list<string> $statements
     */
    public function register(string $name, array $statements): void
    {
        // The stored name is `pluginId:name` in nb_migrations.migration (VARCHAR
        // 191); the id is already bounded to ≤64 by the loader, so bound the name
        // too rather than let an over-long one 1406 → 500 at `nimbus migrate`.
        if ($name === '' || strlen($name) > 120) {
            throw new \InvalidArgumentException("A migration name must be 1–120 characters: \"{$name}\".");
        }
        $this->registry->add($this->pluginId . ':' . $name, $statements, $this->pluginId);
    }
}

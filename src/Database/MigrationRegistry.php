<?php

declare(strict_types=1);

namespace Nimbus\Database;

/**
 * Migrations plugins declare for their own tables, collected at boot and run by
 * the Migrator after core's. Composed once and shared, like the other capability
 * registries: what a plugin registers here must reach the Migrator.
 *
 * A plugin owns only its *own* tables ([ADR 0005](../../../docs/adr/0005-plugin-owned-storage.md));
 * it never issues DDL against core `nb_*` tables. Each migration's name is
 * globally unique (the registrar prefixes it with the plugin id), so it is
 * tracked in `nb_migrations` without colliding with core or another plugin.
 */
final class MigrationRegistry
{
    /** @var list<array{name:string,statements:list<string>,provider:string}> */
    private array $migrations = [];

    /** @param list<string> $statements */
    public function add(string $name, array $statements, string $provider): void
    {
        $this->migrations[] = [
            'name'       => $name,
            'statements' => array_values($statements),
            'provider'   => $provider,
        ];
    }

    /** @return list<array{name:string,statements:list<string>,provider:string}> in registration order */
    public function all(): array
    {
        return $this->migrations;
    }

    /** Drop a provider's migrations — used when a plugin's load fails. */
    public function forgetProvider(string $provider): void
    {
        $this->migrations = array_values(array_filter(
            $this->migrations,
            static fn (array $migration): bool => $migration['provider'] !== $provider,
        ));
    }
}

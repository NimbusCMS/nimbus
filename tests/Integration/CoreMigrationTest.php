<?php

declare(strict_types=1);

namespace Nimbus\Tests\Integration;

use Nimbus\Database\Migrator;

/**
 * The core migration set — the thing every install and every upgrade runs. The
 * bootstrap has already applied all of them to the test database, so this guards
 * the two properties that matter for "no upgrade path yet": the full set applied
 * (the expected tables exist) and the migrator is **idempotent** — re-running it
 * against an up-to-date database applies nothing and errors nothing, so a deploy
 * that runs `migrate` on every boot is safe.
 */
final class CoreMigrationTest extends IntegrationTestCase
{
    private function migrator(): Migrator
    {
        return new Migrator($this->db, dirname(__DIR__, 2) . '/src/Database/migrations');
    }

    public function test_re_running_core_migrations_is_a_no_op(): void
    {
        // Already migrated by the bootstrap; a second run applies nothing.
        self::assertFalse($this->migrator()->pending(), 'no migrations pending after bootstrap');
        $second = $this->migrator()->migrate();
        self::assertSame([], $second->applied, 'a second migrate() applies nothing');
        self::assertSame([], $second->failures, 'and nothing failed');
        self::assertSame([], $this->migrator()->migrate()->applied, 'and is stable on a third run');
    }

    public function test_the_core_tables_all_exist_after_migration(): void
    {
        foreach ([
            'nb_migrations', 'nb_users', 'nb_roles', 'nb_user_roles', 'nb_settings',
            'nb_collections', 'nb_fields', 'nb_entries', 'nb_relations', 'nb_media',
            'nb_media_usage', 'nb_api_tokens', 'nb_login_throttle', 'nb_password_resets',
        ] as $table) {
            self::assertTrue($this->db->tableExists($table), "core table {$table} exists");
        }
    }
}

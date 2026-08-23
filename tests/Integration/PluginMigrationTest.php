<?php

declare(strict_types=1);

namespace Nimbus\Tests\Integration;

use Nimbus\Database\MigrationRegistry;
use Nimbus\Database\Migrator;

/**
 * A plugin's declared migrations run against the real database, after core's,
 * and are tracked in nb_migrations under their namespaced names (ADR 0005).
 *
 * Uses an empty core-migrations directory so only the plugin migration runs;
 * the throwaway table and its nb_migrations rows are cleaned up around each test.
 */
final class PluginMigrationTest extends IntegrationTestCase
{
    private const TABLE  = 'nbtest_widgets';
    private const PREFIX = 'test.plugin:';

    private string $emptyCore;

    protected function setUp(): void
    {
        parent::setUp();
        $this->emptyCore = sys_get_temp_dir() . '/nb-mig-' . bin2hex(random_bytes(4));
        mkdir($this->emptyCore);
        $this->cleanup();
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        @rmdir($this->emptyCore);
    }

    private function cleanup(): void
    {
        $this->db->pdo()->exec('DROP TABLE IF EXISTS ' . self::TABLE);
        $this->db->execute('DELETE FROM nb_migrations WHERE migration LIKE :p', ['p' => self::PREFIX . '%']);
    }

    private function registryWithWidgets(): MigrationRegistry
    {
        $registry = new MigrationRegistry();
        $registry->add(
            self::PREFIX . '001_widgets',
            ['CREATE TABLE ' . self::TABLE . ' (id INT UNSIGNED PRIMARY KEY)'],
            'test.plugin',
        );
        return $registry;
    }

    public function test_a_plugin_migration_creates_its_table_and_is_recorded(): void
    {
        $report = (new Migrator($this->db, $this->emptyCore, $this->registryWithWidgets()))->migrate();

        self::assertSame([self::PREFIX . '001_widgets'], $report->applied);
        self::assertSame([], $report->failures);
        self::assertTrue($report->ok());
        self::assertTrue($this->db->tableExists(self::TABLE));

        $names = array_column(
            $this->db->select('SELECT migration FROM nb_migrations WHERE migration LIKE :p', ['p' => self::PREFIX . '%']),
            'migration',
        );
        self::assertContains(self::PREFIX . '001_widgets', $names);
    }

    public function test_plugin_migrations_are_idempotent(): void
    {
        $migrator = new Migrator($this->db, $this->emptyCore, $this->registryWithWidgets());
        $migrator->migrate();

        self::assertSame([], $migrator->migrate()->applied, 'an applied plugin migration is not re-run');
    }

    public function test_pending_compares_name_sets_not_counts(): void
    {
        // Simulate a churned install: an uninstalled plugin's rows still recorded,
        // inflating the applied count. A count comparison would hide a genuinely
        // unapplied migration; a set comparison does not (PLUG-10).
        $this->db->execute('INSERT INTO nb_migrations (migration, applied_at) VALUES (:m, :t)', ['m' => 'gone.plugin:001', 't' => date('c')]);
        try {
            self::assertTrue(
                (new Migrator($this->db, $this->emptyCore, $this->registryWithWidgets()))->pending(),
                'an unapplied known migration is pending even when stale rows inflate the count',
            );
        } finally {
            $this->db->execute("DELETE FROM nb_migrations WHERE migration = 'gone.plugin:001'");
        }
    }

    // ------------------------------------------------------------ isolation

    private const TABLE_B = 'nbtest_gadgets';

    private function cleanupIsolation(): void
    {
        $this->db->pdo()->exec('DROP TABLE IF EXISTS ' . self::TABLE_B);
        $this->db->execute('DELETE FROM nb_migrations WHERE migration LIKE :a OR migration LIKE :b', ['a' => 'plugin.a:%', 'b' => 'plugin.b:%']);
        $this->db->pdo()->exec('DROP TABLE IF EXISTS nbtest_a');
    }

    /**
     * A broken migration from provider A must not starve provider B or block the
     * whole run — the PLUG-1 regression (red on the old count-and-no-isolation code).
     */
    public function test_one_failing_provider_does_not_wedge_the_others(): void
    {
        $this->cleanupIsolation();
        $registry = new \Nimbus\Database\MigrationRegistry();
        // Provider A: statement 1 creates a table, statement 2 is broken SQL.
        $registry->add('plugin.a:001', ['CREATE TABLE nbtest_a (id INT PRIMARY KEY)', 'THIS IS NOT SQL'], 'plugin.a');
        // A later A migration that must be SKIPPED once 001 fails.
        $registry->add('plugin.a:002', ['CREATE TABLE nbtest_a_two (id INT PRIMARY KEY)'], 'plugin.a');
        // Provider B: a healthy migration that MUST still apply.
        $registry->add('plugin.b:001', ['CREATE TABLE ' . self::TABLE_B . ' (id INT PRIMARY KEY)'], 'plugin.b');

        try {
            $report = (new Migrator($this->db, $this->emptyCore, $registry))->migrate();

            self::assertFalse($report->ok(), 'the run reports a failure');
            self::assertSame(['plugin.b:001'], $report->applied, 'B applied; A did not');
            self::assertCount(1, $report->failures);
            self::assertSame('plugin.a', $report->failures[0]['provider']);
            self::assertSame('plugin.a:001', $report->failures[0]['migration']);
            self::assertStringContainsString('statement 2 of 2', $report->failures[0]['error'], 'the failing statement index is named');

            self::assertTrue($this->db->tableExists(self::TABLE_B), 'provider B is not starved by A');
            self::assertFalse($this->db->tableExists('nbtest_a_two'), "A's later migration is skipped");

            $recorded = array_column($this->db->select("SELECT migration FROM nb_migrations WHERE migration LIKE 'plugin.%'"), 'migration');
            self::assertSame(['plugin.b:001'], $recorded, 'only B is recorded; A stays retryable');
        } finally {
            $this->db->pdo()->exec('DROP TABLE IF EXISTS nbtest_a_two');
            $this->cleanupIsolation();
        }
    }

    /**
     * The core/plugin halt distinction is structural (which loop runs), NOT the
     * provider string — a plugin registering under `provider: 'core'` is still
     * isolated, never granted core's halt-everything behaviour (guards against a
     * PLUG-2 `"id": "core"` re-wedge primitive).
     */
    public function test_a_plugin_named_core_is_still_isolated_not_halting(): void
    {
        $this->cleanupIsolation();
        $registry = new \Nimbus\Database\MigrationRegistry();
        $registry->add('core:001', ['DEFINITELY NOT SQL'], 'core'); // a plugin masquerading as core
        $registry->add('plugin.b:001', ['CREATE TABLE ' . self::TABLE_B . ' (id INT PRIMARY KEY)'], 'plugin.b');

        try {
            // Must NOT throw (that would be the core-halt behaviour); isolated instead.
            $report = (new Migrator($this->db, $this->emptyCore, $registry))->migrate();

            self::assertFalse($report->ok());
            self::assertSame(['plugin.b:001'], $report->applied, 'the healthy provider still applies — no halt');
            self::assertSame('core', $report->failures[0]['provider']);
            self::assertTrue($this->db->tableExists(self::TABLE_B));
        } finally {
            $this->db->execute("DELETE FROM nb_migrations WHERE migration = 'core:001'");
            $this->cleanupIsolation();
        }
    }

    /**
     * A genuine CORE (file-loop) migration failure fails closed — it throws and no
     * plugin migration is attempted.
     */
    public function test_a_core_migration_failure_throws_and_halts(): void
    {
        $badCore = sys_get_temp_dir() . '/nb-badcore-' . bin2hex(random_bytes(4));
        mkdir($badCore);
        file_put_contents($badCore . '/001_broken.php', "<?php return ['NOT VALID SQL'];");
        $this->cleanupIsolation();

        $registry = new \Nimbus\Database\MigrationRegistry();
        $registry->add('plugin.b:001', ['CREATE TABLE ' . self::TABLE_B . ' (id INT PRIMARY KEY)'], 'plugin.b');

        try {
            (new Migrator($this->db, $badCore, $registry))->migrate();
            self::fail('a core migration failure must throw');
        } catch (\Nimbus\Database\MigrationFailed $e) {
            self::assertStringContainsString('001_broken.php', $e->getMessage());
            self::assertFalse($this->db->tableExists(self::TABLE_B), 'no plugin migration runs after a core failure');
        } finally {
            @unlink($badCore . '/001_broken.php');
            @rmdir($badCore);
            $this->cleanupIsolation();
        }
    }
}

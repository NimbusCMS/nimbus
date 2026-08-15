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
        $ran = (new Migrator($this->db, $this->emptyCore, $this->registryWithWidgets()))->migrate();

        self::assertSame([self::PREFIX . '001_widgets'], $ran);
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

        self::assertSame([], $migrator->migrate(), 'an applied plugin migration is not re-run');
    }
}

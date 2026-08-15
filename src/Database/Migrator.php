<?php

declare(strict_types=1);

namespace Nimbus\Database;

/**
 * A minimal forward-only migrator. Each file in migrations/ returns an array of
 * SQL statements; applied files are recorded in nb_migrations so migrate() is
 * idempotent.
 *
 * Plugins may declare migrations for their own tables ([ADR 0005](../../../docs/adr/0005-plugin-owned-storage.md));
 * they run *after* core's, since a plugin's tables may reference core's, and are
 * tracked in the same nb_migrations table under their globally-unique names.
 */
final class Migrator
{
    public function __construct(
        private Connection $db,
        private string $path,
        private ?MigrationRegistry $plugins = null,
    ) {
    }

    /** @return string[] names of migrations applied this run */
    public function migrate(): array
    {
        $this->ensureLog();
        $applied = $this->applied();
        $ran     = [];

        // Core file migrations first.
        foreach ($this->files() as $file) {
            $name = basename($file);
            if (in_array($name, $applied, true)) {
                continue;
            }
            /** @var string[] $statements */
            $statements = require $file;
            $this->apply($name, (array) $statements);
            $ran[] = $name;
        }

        // Then plugin-declared migrations, in registration order.
        foreach ($this->plugins?->all() ?? [] as $migration) {
            if (in_array($migration['name'], $applied, true)) {
                continue;
            }
            $this->apply($migration['name'], $migration['statements']);
            $ran[] = $migration['name'];
        }

        return $ran;
    }

    public function pending(): bool
    {
        if (!$this->db->tableExists('nb_migrations')) {
            return true;
        }
        $total = count($this->files()) + count($this->plugins?->all() ?? []);
        return count($this->applied()) < $total;
    }

    /** @param array<int,mixed> $statements */
    private function apply(string $name, array $statements): void
    {
        foreach ($statements as $sql) {
            $sql = trim((string) $sql);
            if ($sql !== '') {
                $this->db->pdo()->exec($sql);
            }
        }
        $this->db->execute(
            'INSERT INTO nb_migrations (migration, applied_at) VALUES (:m, :t)',
            ['m' => $name, 't' => date('c')],
        );
    }

    private function ensureLog(): void
    {
        $this->db->pdo()->exec(
            'CREATE TABLE IF NOT EXISTS nb_migrations (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                migration VARCHAR(191) NOT NULL UNIQUE,
                applied_at VARCHAR(40) NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        );
    }

    /** @return string[] */
    private function applied(): array
    {
        return array_map(
            static fn (array $r): string => (string) $r['migration'],
            $this->db->select('SELECT migration FROM nb_migrations'),
        );
    }

    /** @return string[] absolute file paths, sorted */
    private function files(): array
    {
        $files = glob(rtrim($this->path, '/') . '/*.php') ?: [];
        sort($files);
        return $files;
    }
}

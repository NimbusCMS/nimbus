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

    /**
     * Apply pending migrations. Core file migrations run first (a plugin's tables
     * may reference core's); a **core** failure throws {@see MigrationFailed} and
     * halts — pressing plugins onto a half-built core schema only multiplies the
     * damage. Then plugin migrations run, **isolated per provider**: the first
     * failure in a provider records the error, skips that provider's remaining
     * migrations, and moves on to the next provider — so one broken plugin can no
     * longer wedge the others or block core upgrades (PLUG-1).
     *
     * The core-vs-plugin distinction is which loop runs, never the provider
     * *string* (a plugin could name its id `core`), so no plugin can hijack the
     * halt or masquerade as core.
     *
     * @throws MigrationFailed a core migration failed (fail closed)
     */
    public function migrate(): MigrationReport
    {
        $this->ensureLog();          // bookkeeping — a failure here propagates (catastrophic)
        $applied = $this->applied();
        $ran     = [];

        // Core file migrations first — fail closed (throw), never isolated.
        foreach ($this->files() as $file) {
            $name = basename($file);
            if (in_array($name, $applied, true)) {
                continue;
            }
            /** @var array<int,mixed> $statements */
            $statements = require $file;
            try {
                $this->runStatements((array) $statements);
            } catch (\PDOException $e) {
                throw new MigrationFailed("Core migration \"{$name}\" failed: " . $e->getMessage(), 0, $e);
            }
            $this->record($name);    // propagates if it throws — the record IS the success
            $ran[] = $name;
        }

        // Then plugin-declared migrations, in registration order, isolated per
        // provider. The registry list is provider-contiguous.
        $failures       = [];
        $failedProvider = [];
        foreach ($this->plugins?->all() ?? [] as $migration) {
            $provider = $migration['provider'];
            $name     = $migration['name'];
            if (in_array($name, $applied, true) || isset($failedProvider[$provider])) {
                continue; // already applied, or this provider already failed → skip its rest
            }
            try {
                $this->runStatements($migration['statements']);
            } catch (\PDOException $e) {
                // Isolate: record and skip the rest of THIS provider; other
                // providers (and core, already done) are untouched.
                $failedProvider[$provider] = true;
                $failures[] = ['provider' => $provider, 'migration' => $name, 'error' => $e->getMessage()];
                continue;
            }
            $this->record($name);
            $ran[] = $name;
        }

        return new MigrationReport($ran, $failures);
    }

    /**
     * Are any known migrations (core files + registered plugin migrations) not yet
     * recorded? Compares name **sets**, not counts — an uninstalled plugin's
     * lingering `nb_migrations` rows inflate the applied count, so a count
     * comparison could hide a genuinely pending core migration (PLUG-10).
     */
    public function pending(): bool
    {
        if (!$this->db->tableExists('nb_migrations')) {
            return true;
        }
        $applied = $this->applied();
        $known   = array_map('basename', $this->files());
        foreach ($this->plugins?->all() ?? [] as $migration) {
            $known[] = $migration['name'];
        }
        foreach ($known as $name) {
            if (!in_array($name, $applied, true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Run a migration's statements. On a failure the thrown PDOException names the
     * failing statement index — the first thing an author debugging a partial
     * (auto-committed, non-rollback-able) DDL migration needs.
     *
     * @param array<int,mixed> $statements
     */
    private function runStatements(array $statements): void
    {
        $total = count($statements);
        foreach (array_values($statements) as $i => $sql) {
            $sql = trim((string) $sql);
            if ($sql === '') {
                continue;
            }
            try {
                $this->db->pdo()->exec($sql);
            } catch (\PDOException $e) {
                throw new \PDOException(sprintf('statement %d of %d: %s', $i + 1, $total, $e->getMessage()), 0, $e);
            }
        }
    }

    /** Record a migration as applied — bookkeeping; a failure here is catastrophic and propagates. */
    private function record(string $name): void
    {
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

<?php

declare(strict_types=1);

namespace Nimbus\Tests\Integration;

use Nimbus\Database\Connection;
use Nimbus\Database\MigrationFailed;
use Nimbus\Database\Migrator;
use PHPUnit\Framework\TestCase;

/**
 * DATA-4: a migration that fails partway used to be unrecoverable — MySQL
 * auto-commits DDL, so earlier statements stuck while the migration was never
 * recorded, and a re-run hit "already exists" and aborted forever (the documented
 * DROP + DELETE ops dance). Now `runStatements` treats an "object already exists"
 * error as an already-applied no-op, so a partial migration self-heals on re-run,
 * while a genuine error still fails closed.
 *
 * Uses a throwaway database + a temp migrations directory for deterministic
 * control over partial-apply states.
 */
final class MigrationRecoveryTest extends TestCase
{
    private \PDO $root;
    private string $dbName;
    private Connection $db;
    private string $dir;

    protected function setUp(): void
    {
        $this->root = new \PDO(
            sprintf('mysql:host=%s;port=%d', NB_TEST_DB['host'], NB_TEST_DB['port']),
            NB_TEST_DB['user'],
            NB_TEST_DB['pass'],
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION],
        );
        $this->dbName = 'nb_rec_' . bin2hex(random_bytes(4));
        $this->root->exec("CREATE DATABASE `{$this->dbName}` CHARACTER SET utf8mb4");
        $this->db = new Connection([
            'host' => NB_TEST_DB['host'], 'port' => NB_TEST_DB['port'],
            'name' => $this->dbName, 'user' => NB_TEST_DB['user'], 'pass' => NB_TEST_DB['pass'],
        ]);
        $this->dir = sys_get_temp_dir() . '/nimbus-mig-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0o775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
        $this->root->exec("DROP DATABASE `{$this->dbName}`");
    }

    /** @param list<string> $statements */
    private function writeMigration(string $name, array $statements): void
    {
        file_put_contents($this->dir . '/' . $name, "<?php\nreturn " . var_export($statements, true) . ";\n");
    }

    private function migrator(): Migrator
    {
        return new Migrator($this->db, $this->dir);
    }

    public function test_a_partially_applied_create_file_self_heals_on_rerun(): void
    {
        $this->writeMigration('001_create.php', [
            'CREATE TABLE nb_rec_a (id INT PRIMARY KEY)',
            'CREATE TABLE nb_rec_b (id INT PRIMARY KEY)',
        ]);
        // Simulate a partial apply: the first table exists, the migration was never recorded.
        $this->db->execute('CREATE TABLE nb_rec_a (id INT PRIMARY KEY)');

        $report = $this->migrator()->migrate();

        self::assertContains('001_create.php', $report->applied, 'the file completes and is recorded');
        self::assertTrue($this->db->tableExists('nb_rec_b'), 'the not-yet-applied statement ran');
    }

    public function test_a_partially_applied_alter_file_self_heals_on_rerun(): void
    {
        // The case CREATE TABLE IF NOT EXISTS cannot fix (MySQL 8 has no
        // ADD COLUMN IF NOT EXISTS) — 011_token_role's ALTER pair in miniature.
        $this->writeMigration('001_alter.php', [
            'CREATE TABLE nb_rec_c (id INT PRIMARY KEY)',
            'ALTER TABLE nb_rec_c ADD COLUMN nm VARCHAR(10) NULL',
        ]);
        // Simulate a partial apply: the table AND the column already exist.
        $this->db->execute('CREATE TABLE nb_rec_c (id INT PRIMARY KEY, nm VARCHAR(10) NULL)');

        $report = $this->migrator()->migrate();

        self::assertContains('001_alter.php', $report->applied, 'the ALTER file self-heals and records');
    }

    public function test_a_genuinely_bad_statement_still_fails_closed(): void
    {
        $this->writeMigration('001_bad.php', [
            'CREATE TABLE nb_rec_d (id INT PRIMARY KEY)',
            'THIS IS NOT VALID SQL',
        ]);

        $this->expectException(MigrationFailed::class);
        $this->migrator()->migrate();
    }
}

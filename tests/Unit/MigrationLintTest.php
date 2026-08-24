<?php

declare(strict_types=1);

namespace Nimbus\Tests\Unit;

use Nimbus\Database\MigrationRegistry;
use Nimbus\Plugin\PluginCapabilities;
use Nimbus\Plugin\PluginContext;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * FU-11: the honest-accident guard that rejects a plugin migration statement
 * which would create/alter/drop/rename/index/DML a core `nb_*` table, before
 * `nimbus migrate` hands it to MySQL's irreversible auto-commit DDL. NOT a
 * sandbox (ADR 0001 — a plugin is trusted in-process code); a determined plugin
 * bypasses it with dynamic SQL or a raw PDO. This corpus is the guard's spec:
 * every "reject" case must throw, every "allow" case must pass. A future
 * "simplify the regex" refactor has to turn one of these red.
 */
final class MigrationLintTest extends TestCase
{
    /** Register a single statement through the real registrar surface. */
    private function register(string $statement): void
    {
        $registry = new MigrationRegistry();
        $context  = new PluginContext(new PluginCapabilities(migrations: $registry), 'nimbuscms.analytics');
        $context->migrations()->register('001_test', [$statement]);
    }

    private function assertRejected(string $sql): void
    {
        try {
            $this->register($sql);
            self::fail("expected the migration lint to reject: {$sql}");
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('reserved for Nimbus core', $e->getMessage(), $sql);
        }
    }

    private function assertAllowed(string $sql): void
    {
        $this->register($sql); // must not throw
        $this->addToAssertionCount(1);
    }

    /** @return iterable<string,array{string}> */
    public static function evasions(): iterable
    {
        yield 'plain create'        => ['CREATE TABLE nb_users (id INT)'];
        yield 'lowercase'           => ['create table nb_users (id int)'];
        yield 'drop'                => ['DROP TABLE nb_users'];
        yield 'drop if exists'      => ['DROP TABLE IF EXISTS nb_users'];
        yield 'backticked'          => ['DROP TABLE `nb_users`'];
        yield 'alter add column'    => ['ALTER TABLE nb_users ADD COLUMN x INT'];
        yield 'truncate no TABLE'   => ['TRUNCATE nb_users'];
        yield 'truncate table'      => ['TRUNCATE TABLE nb_users'];
        yield 'comma list, second'  => ['DROP TABLE analytics_old, nb_users'];
        yield 'newline separated'   => ["DROP\nTABLE\nnb_users"];
        yield 'tab separated'       => ["DROP\tTABLE\tnb_users"];
        yield 'block comment split' => ['DROP/**/TABLE/**/nb_users'];
        yield 'line comment split'  => ["DROP TABLE -- oops\nnb_users"];
        yield 'versioned comment'   => ['/*! DROP TABLE nb_users */'];
        yield 'rename target'       => ['RENAME TABLE analytics_x TO nb_cache'];
        yield 'alter rename target' => ['ALTER TABLE analytics_x RENAME TO nb_cache'];
        yield 'create index on'     => ['CREATE INDEX i ON nb_users (email)'];
        yield 'drop index on'       => ['DROP INDEX i ON nb_users'];
        yield 'insert into'         => ['INSERT INTO nb_user_roles (user_id, role_id) VALUES (1, 1)'];
        yield 'replace into'        => ["REPLACE INTO nb_settings (`key`, `value`) VALUES ('x', 'y')"];
        yield 'update'              => ["UPDATE nb_users SET role = 'admin'"];
        yield 'delete from'         => ['DELETE FROM nb_login_throttle'];
    }

    #[DataProvider('evasions')]
    public function test_a_statement_touching_a_core_table_is_rejected(string $sql): void
    {
        $this->assertRejected($sql);
    }

    /** @return iterable<string,array{string}> */
    public static function carveOuts(): iterable
    {
        yield 'own table'            => ['CREATE TABLE analytics_hits (id INT PRIMARY KEY)'];
        yield 'FK reference to core' => ['CREATE TABLE analytics_hits (uid INT, FOREIGN KEY (uid) REFERENCES nb_users(id))'];
        yield 'bare FK reference'    => ['ALTER TABLE analytics_hits ADD FOREIGN KEY (uid) REFERENCES nb_users (id)'];
        yield 'nb mid-name no us'    => ['CREATE TABLE analytics_nbmetrics (id INT)'];
        yield 'nb_ mid-identifier'   => ['CREATE TABLE analytics_nb_x (id INT)'];
        yield 'column named nb_'     => ['ALTER TABLE analytics_hits ADD COLUMN nb_count INT'];
        yield 'nb_ in a comment'     => ['CREATE TABLE analytics_hits (id INT) -- drop table nb_users'];
        yield 'nb_ in a value'       => ["INSERT INTO analytics_notes (body) VALUES ('do not DROP TABLE nb_users')"];
        yield 'read from core'       => ['INSERT INTO analytics_snapshot SELECT id FROM nb_users'];
        yield 'own DML'              => ['DELETE FROM analytics_hits WHERE id = 1'];
    }

    #[DataProvider('carveOuts')]
    public function test_a_legitimate_statement_is_allowed(string $sql): void
    {
        $this->assertAllowed($sql);
    }

    public function test_every_core_migration_statement_is_flagged_if_a_plugin_registers_it(): void
    {
        // Copy-pasting a core migration is the accident most likely to happen —
        // and doubles as a drift guard when core migrations grow.
        $dir = \dirname(__DIR__, 2) . '/src/Database/migrations';
        $checked = 0;
        foreach (glob($dir . '/*.php') ?: [] as $file) {
            /** @var list<string> $statements */
            $statements = require $file;
            foreach ($statements as $sql) {
                // Core migrations are CREATE/ALTER TABLE nb_* + INSERTs — each
                // must be flagged as a would-be plugin violation.
                if (preg_match('/\bnb_\w+/', (string) $sql) === 1) {
                    $this->assertRejected((string) $sql);
                    $checked++;
                }
            }
        }
        self::assertGreaterThan(10, $checked, 'the core migrations should exercise many core-table statements');
    }

    public function test_the_diagnostic_message_teaches_and_disclaims_a_sandbox(): void
    {
        try {
            $this->register('DROP TABLE nb_users');
            self::fail('expected rejection');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('nb_users', $e->getMessage(), 'names the offending table');
            self::assertStringContainsString('ADR 0005', $e->getMessage(), 'cites the convention');
            self::assertStringContainsString('not a sandbox', $e->getMessage(), 'does not oversell as a security boundary');
        }
    }
}

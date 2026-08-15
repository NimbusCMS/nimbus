<?php

declare(strict_types=1);

namespace Nimbus\Tests\Integration;

use Nimbus\Plugin\PluginCapabilities;
use Nimbus\Plugin\PluginContext;
use Nimbus\Plugin\PluginStorage;
use RuntimeException;

/**
 * A plugin's scoped storage (ADR 0005): parameterised read/write against a
 * table the plugin owns, exercised against the real database.
 */
final class PluginStorageTest extends IntegrationTestCase
{
    private const TABLE = 'nbtest_hits';

    private PluginStorage $storage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db->pdo()->exec('DROP TABLE IF EXISTS ' . self::TABLE);
        $this->db->pdo()->exec(
            'CREATE TABLE ' . self::TABLE . ' (
                id   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                path VARCHAR(191) NOT NULL,
                hits INT UNSIGNED NOT NULL DEFAULT 0
            ) ENGINE=InnoDB',
        );
        $this->storage = new PluginStorage($this->db);
    }

    protected function tearDown(): void
    {
        $this->db->pdo()->exec('DROP TABLE IF EXISTS ' . self::TABLE);
        parent::tearDown();
    }

    private function record(string $path, int $hits): void
    {
        $this->storage->insert(
            'INSERT INTO ' . self::TABLE . ' (path, hits) VALUES (:p, :h)',
            ['p' => $path, 'h' => $hits],
        );
    }

    public function test_insert_select_and_execute_round_trip(): void
    {
        $this->record('/home', 3);
        $this->record('/about', 1);

        // select — assert on the paths (unambiguous strings), ordered by hits.
        $paths = array_column(
            $this->storage->select('SELECT path FROM ' . self::TABLE . ' ORDER BY hits DESC'),
            'path',
        );
        self::assertSame(['/home', '/about'], $paths);

        // selectOne
        $row = $this->storage->selectOne('SELECT hits FROM ' . self::TABLE . ' WHERE path = :p', ['p' => '/home']);
        self::assertSame(3, (int) ($row['hits'] ?? 0));

        // execute — an increment
        $affected = $this->storage->execute('UPDATE ' . self::TABLE . ' SET hits = hits + 1 WHERE path = :p', ['p' => '/home']);
        self::assertSame(1, $affected);
        $row = $this->storage->selectOne('SELECT hits FROM ' . self::TABLE . ' WHERE path = :p', ['p' => '/home']);
        self::assertSame(4, (int) ($row['hits'] ?? 0));
    }

    public function test_a_thrown_transaction_rolls_back(): void
    {
        $this->storage->transaction(function (): void {
            $this->record('/kept', 1);
        });
        self::assertNotNull(
            $this->storage->selectOne('SELECT id FROM ' . self::TABLE . ' WHERE path = :p', ['p' => '/kept']),
            'a committed transaction persists',
        );

        try {
            $this->storage->transaction(function (): void {
                $this->record('/dropped', 1);
                throw new RuntimeException('boom');
            });
        } catch (RuntimeException) {
            // expected — the throw propagates out of the transaction
        }

        self::assertNull(
            $this->storage->selectOne('SELECT id FROM ' . self::TABLE . ' WHERE path = :p', ['p' => '/dropped']),
            'a thrown transaction rolls back',
        );
    }

    public function test_storage_without_a_connection_throws(): void
    {
        $context = new PluginContext(new PluginCapabilities(), 'nimbuscms.analytics');

        $this->expectException(RuntimeException::class);
        $context->storage();
    }
}

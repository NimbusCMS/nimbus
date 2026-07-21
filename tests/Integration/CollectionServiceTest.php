<?php

declare(strict_types=1);

namespace Nimbus\Tests\Integration;

use Nimbus\Content\CollectionRepository;
use Nimbus\Content\CollectionService;
use Nimbus\Content\DuplicateHandle;
use Nimbus\Database\Connection;

final class CollectionServiceTest extends IntegrationTestCase
{
    private CollectionService $service;
    private CollectionRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo    = new CollectionRepository($this->db);
        $this->service = new CollectionService($this->db, $this->repo);
    }

    private function make(string $handle, array $fields = []): int
    {
        return $this->service->create($handle, ucfirst($handle), '#', '', ['kind' => 'collection', 'permissions' => ['manage' => []]], $fields);
    }

    public function test_duplicate_handle_raises_a_domain_exception(): void
    {
        $this->make('posts');

        try {
            $this->make('posts');
            self::fail('expected DuplicateHandle');
        } catch (DuplicateHandle $e) {
            // Named, catchable, and carrying the handle — the controller turns
            // this into a form error instead of dropping the submission.
            self::assertSame('posts', $e->handle);
            self::assertInstanceOf(\PDOException::class, $e->getPrevious());
            self::assertTrue(Connection::isDuplicateKey($e->getPrevious()));
        }
    }

    public function test_duplicate_handle_leaves_the_original_untouched(): void
    {
        $first = $this->make('posts', [
            ['handle' => 'body', 'label' => 'Body', 'type' => 'text', 'required' => false, 'options' => []],
        ]);

        try {
            $this->make('posts', [
                ['handle' => 'other', 'label' => 'Other', 'type' => 'text', 'required' => false, 'options' => []],
            ]);
        } catch (DuplicateHandle) {
            // expected
        }

        $collection = $this->repo->findByHandle('posts');
        self::assertNotNull($collection);
        self::assertSame($first, $collection->id);
        self::assertCount(1, $collection->fields);
        self::assertSame('body', $collection->fields[0]->handle, 'the loser must not have rewritten the winner');
    }

    public function test_other_database_errors_are_not_swallowed_as_duplicates(): void
    {
        $this->expectException(\PDOException::class);

        $this->service->create('toolong', 'T', '#', '', ['kind' => 'collection', 'permissions' => ['manage' => []]], [
            ['handle' => 'f', 'label' => str_repeat('x', 300), 'type' => 'text', 'required' => false, 'options' => []],
        ]);
    }

    public function test_field_handle_is_unique_within_a_collection(): void
    {
        $id = $this->make('posts', [
            ['handle' => 'body', 'label' => 'Body', 'type' => 'text', 'required' => false, 'options' => []],
        ]);
        $this->expectException(\PDOException::class);
        $this->db->execute(
            "INSERT INTO nb_fields (collection_id, handle, label, type, required, sort, created_at) VALUES (:c, 'body', 'Body 2', 'text', 0, 1, NOW())",
            ['c' => $id]
        );
    }

    public function test_collection_and_fields_roll_back_together(): void
    {
        // A field label longer than VARCHAR(120) fails under strict mode mid-transaction.
        try {
            $this->service->create('rollbacktest', 'RB', '#', '', ['kind' => 'collection', 'permissions' => ['manage' => []]], [
                ['handle' => 'f', 'label' => str_repeat('x', 300), 'type' => 'text', 'required' => false, 'options' => []],
            ]);
            self::fail('expected the field insert to fail');
        } catch (\Throwable) {
            // the collection insert must have rolled back with the failed field insert
            self::assertNull($this->repo->findByHandle('rollbacktest'));
        }
    }
}

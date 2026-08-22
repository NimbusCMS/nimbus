<?php

declare(strict_types=1);

namespace Nimbus\Tests\Integration;

use Nimbus\Content\CollectionRepository;
use Nimbus\Content\CollectionService;
use Nimbus\Content\EntryRepository;

/**
 * Repository-level guarantees behind the admin listing hardening: the paginated
 * `EntryRepository::forCollection` window + search-aware `countForCollection`,
 * and the grouped `CollectionRepository::fieldCounts`/`entryCounts` that replace
 * the per-collection N+1 (correct, and zero-safe via map-with-default).
 */
final class ListingRepositoryTest extends IntegrationTestCase
{
    private CollectionRepository $collections;
    private EntryRepository $entries;

    protected function setUp(): void
    {
        parent::setUp();
        $this->collections = new CollectionRepository($this->db);
        $this->entries     = new EntryRepository($this->db);
    }

    private function makeCollection(string $handle): int
    {
        return (new CollectionService($this->db, $this->collections))
            ->create($handle, ucfirst($handle), '#', '', ['kind' => 'collection', 'permissions' => ['manage' => []]], []);
    }

    private function seed(int $collectionId, int $count, string $prefix = 'Entry'): void
    {
        for ($i = 1; $i <= $count; $i++) {
            $this->db->insert(
                "INSERT INTO nb_entries (collection_id, title, slug, status, data, created_at, updated_at)
                 VALUES (:c, :t, :s, 'draft', '{}', NOW(), NOW())",
                ['c' => $collectionId, 't' => "{$prefix} {$i}", 's' => strtolower($prefix) . "-{$i}"],
            );
        }
    }

    public function test_forCollection_windows_and_counts(): void
    {
        $id = $this->makeCollection('posts');
        $this->seed($id, 30);

        self::assertCount(25, $this->entries->forCollection($id, null, 25, 0), 'first window');
        self::assertCount(5, $this->entries->forCollection($id, null, 25, 25), 'second window');
        self::assertSame(30, $this->entries->countForCollection($id));
    }

    public function test_count_and_window_are_search_aware(): void
    {
        $id = $this->makeCollection('posts');
        $this->seed($id, 30, 'Alpha');
        $this->seed($id, 3, 'Beta');

        self::assertSame(33, $this->entries->countForCollection($id));
        self::assertSame(3, $this->entries->countForCollection($id, 'Beta'));
        self::assertCount(3, $this->entries->forCollection($id, 'Beta', 25, 0));
    }

    public function test_forCollection_without_a_limit_returns_all(): void
    {
        $id = $this->makeCollection('posts');
        $this->seed($id, 30);

        self::assertCount(30, $this->entries->forCollection($id), 'BC: no limit → the full set');
    }

    public function test_grouped_counts_are_correct_and_zero_safe(): void
    {
        $posts = $this->makeCollection('posts');
        $empty = $this->makeCollection('empty');
        $this->seed($posts, 4);

        $entryCounts = $this->collections->entryCounts();
        $fieldCounts = $this->collections->fieldCounts();

        self::assertSame(4, $entryCounts[$posts] ?? 0);
        self::assertSame(0, $entryCounts[$empty] ?? 0, 'a zero-entry collection is absent → defaults to 0');
        self::assertSame(0, $fieldCounts[$posts] ?? 0, 'created with no fields');
        self::assertArrayNotHasKey($empty, $fieldCounts, 'no row for a collection with no fields');
    }
}

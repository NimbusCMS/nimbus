<?php

declare(strict_types=1);

namespace Nimbus\Tests\Integration;

use Nimbus\Api\EntryOpContext;
use Nimbus\Api\EntryOperations;
use Nimbus\Api\EntryOpStatus;
use Nimbus\Api\Precondition;
use Nimbus\Api\TokenPrincipal;
use Nimbus\Content\Collection;
use Nimbus\Content\CollectionRepository;
use Nimbus\Content\CollectionService;
use Nimbus\Content\EntryConcurrencyConflict;
use Nimbus\Content\EntryInput;
use Nimbus\Content\EntryRepository;
use Nimbus\Content\EntryService;
use Nimbus\Content\FieldTypeRegistry;
use Nimbus\Content\RelationRepository;
use Nimbus\Content\SaveEntryResult;
use Nimbus\Support\CoreEvents;
use Nimbus\Support\EventDispatcher;

/**
 * API-4: optimistic concurrency is an atomic compare-and-swap, not check-then-act.
 * A write carrying a version that no longer matches the row (someone wrote in the
 * read→write window) is refused — no lost update. These tests reach the CAS with a
 * genuinely stale version; a "two sequential writers" test would be vacuous (the
 * second re-reads and the existing precondition catches it before the CAS).
 */
final class EntryConcurrencyTest extends IntegrationTestCase
{
    private EntryService $service;
    private EntryRepository $entries;
    private CollectionRepository $collections;
    private EventDispatcher $events;

    protected function setUp(): void
    {
        parent::setUp();
        $this->collections = new CollectionRepository($this->db);
        $this->events      = new EventDispatcher();
        $this->entries     = new EntryRepository($this->db);
        $this->service     = new EntryService($this->db, $this->entries, new RelationRepository($this->db), new FieldTypeRegistry(), $this->events);
    }

    private function collection(string $handle): Collection
    {
        $id = (new CollectionService($this->db, $this->collections))->create($handle, ucfirst($handle), '#', '', ['kind' => 'collection', 'permissions' => ['manage' => []]], []);
        return $this->collections->find($id);
    }

    private function versionOf(int $collectionId, int $id): int
    {
        return (int) $this->db->selectOne('SELECT version FROM nb_entries WHERE collection_id = :c AND id = :id', ['c' => $collectionId, 'id' => $id])['version'];
    }

    // -------------------------------------------------------- service-level CAS

    public function test_a_stale_expected_version_conflicts_and_writes_nothing(): void
    {
        $c   = $this->collection('posts');
        $id  = (int) $this->service->save($c, new EntryInput('Hello', 'hello', 'draft', []), null, null)->entryId;
        $old = $this->versionOf($c->id, $id); // 1

        // A concurrent writer bumps the row → version 2.
        $this->service->save($c, new EntryInput('Bumped', 'hello', 'draft', []), $id, null);

        $saved = [];
        $this->events->listen(CoreEvents::ENTRY_SAVED, function () use (&$saved): void {
            $saved[] = true;
        });

        try {
            $this->service->save($c, new EntryInput('Loser', 'hello', 'draft', []), $id, null, $old);
            self::fail('a stale expected version must throw');
        } catch (EntryConcurrencyConflict) {
            // expected
        }

        $row = $this->db->selectOne('SELECT title, version FROM nb_entries WHERE id = :id', ['id' => $id]);
        self::assertSame('Bumped', $row['title'], 'the losing write did not apply');
        self::assertSame(2, (int) $row['version'], 'the version was not bumped by the failed write');
        self::assertSame([], $saved, 'no ENTRY_SAVED event fires on a conflict');
    }

    public function test_a_stale_expected_version_delete_leaves_the_row(): void
    {
        $c   = $this->collection('posts');
        $id  = (int) $this->service->save($c, new EntryInput('Hello', 'hello', 'draft', []), null, null)->entryId;
        $old = $this->versionOf($c->id, $id);
        $this->service->save($c, new EntryInput('Bumped', 'hello', 'draft', []), $id, null); // → v2

        $deleted = [];
        $this->events->listen(CoreEvents::ENTRY_DELETED, function () use (&$deleted): void {
            $deleted[] = true;
        });

        try {
            $this->service->delete($c, $id, $old);
            self::fail('a stale delete must throw');
        } catch (EntryConcurrencyConflict) {
            // expected
        }

        self::assertNotNull($this->db->selectOne('SELECT id FROM nb_entries WHERE id = :id', ['id' => $id]), 'the row survives a stale delete');
        self::assertSame([], $deleted, 'no ENTRY_DELETED event on a conflict');
    }

    public function test_a_matching_version_succeeds_and_bumps(): void
    {
        $c  = $this->collection('posts');
        $id = (int) $this->service->save($c, new EntryInput('Hello', 'hello', 'draft', []), null, null)->entryId;
        $v  = $this->versionOf($c->id, $id);

        $this->service->save($c, new EntryInput('Edited', 'hello', 'draft', []), $id, null, $v);

        self::assertSame($v + 1, $this->versionOf($c->id, $id), 'the CAS applied and bumped the version');
    }

    public function test_a_no_change_save_with_the_current_version_still_succeeds(): void
    {
        // Pins the load-bearing invariant: version = version + 1 guarantees a
        // matched row always *changes*, so rowCount reflects a match even with an
        // identical payload — no spurious 412. (Guards a future dirty-check.)
        $c  = $this->collection('posts');
        $id = (int) $this->service->save($c, new EntryInput('Same', 'same', 'draft', []), null, null)->entryId;
        $v  = $this->versionOf($c->id, $id);

        $this->service->save($c, new EntryInput('Same', 'same', 'draft', []), $id, null, $v); // identical values

        self::assertSame($v + 1, $this->versionOf($c->id, $id), 'an identical-payload CAS is not a false conflict');
    }

    public function test_no_expected_version_is_last_write_wins(): void
    {
        // The admin path passes no expected version — a save still applies against
        // a concurrently-bumped row (no CAS), preserving today's behavior.
        $c   = $this->collection('posts');
        $id  = (int) $this->service->save($c, new EntryInput('Hello', 'hello', 'draft', []), null, null)->entryId;
        $this->service->save($c, new EntryInput('Bumped', 'hello', 'draft', []), $id, null); // concurrent bump → v2

        $result = $this->service->save($c, new EntryInput('Admin Edit', 'hello', 'draft', []), $id, null); // no expected version

        self::assertTrue($result->successful, 'admin save is not version-guarded');
        self::assertSame('Admin Edit', $this->db->selectOne('SELECT title FROM nb_entries WHERE id = :id', ['id' => $id])['title']);
    }

    // --------------------------------------- EntryOperations maps a conflict → 412

    public function test_a_conflict_maps_to_precondition_failed_not_a_500(): void
    {
        $c  = $this->collection('posts');
        $id = (int) $this->service->save($c, new EntryInput('Hello', 'hello', 'draft', []), null, null)->entryId;

        // A double whose write layer always loses the CAS — proves EntryOperations
        // catches the conflict and maps it to a precondition failure (412), never
        // lets it reach the generic 500 boundary.
        $conflicting = new class ($this->db, $this->entries, new RelationRepository($this->db), new FieldTypeRegistry(), $this->events) extends EntryService {
            public function save(Collection $collection, EntryInput $input, ?int $entryId, ?int $userId, ?int $expectedVersion = null): SaveEntryResult
            {
                throw new EntryConcurrencyConflict();
            }

            public function delete(Collection $collection, int $entryId, ?int $expectedVersion = null): bool
            {
                throw new EntryConcurrencyConflict();
            }
        };

        $ops       = new EntryOperations($this->db, new FieldTypeRegistry(), $this->events, $conflicting);
        $principal = new TokenPrincipal(1, 'T', ['posts:write']);
        $ctx       = new EntryOpContext('127.0.0.1', '/api');

        $update = $ops->update($principal, $ctx, 'posts', 'hello', ['title' => 'X'], Precondition::version(1));
        self::assertSame(EntryOpStatus::PreconditionFailed, $update->status, 'a lost CAS is a 412, not a 500');

        $delete = $ops->delete($principal, $ctx, 'posts', 'hello', Precondition::version(1));
        self::assertSame(EntryOpStatus::PreconditionFailed, $delete->status);
    }
}

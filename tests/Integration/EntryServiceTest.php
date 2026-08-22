<?php

declare(strict_types=1);

namespace Nimbus\Tests\Integration;

use Nimbus\Content\Collection;
use Nimbus\Content\CollectionRepository;
use Nimbus\Content\CollectionService;
use Nimbus\Content\EntryInput;
use Nimbus\Content\EntryRepository;
use Nimbus\Content\EntryService;
use Nimbus\Content\FieldTypeRegistry;
use Nimbus\Content\RelationRepository;
use Nimbus\Support\CoreEvents;
use Nimbus\Support\EventDispatcher;

final class EntryServiceTest extends IntegrationTestCase
{
    private EntryService $service;
    private CollectionRepository $collections;
    private EventDispatcher $events;

    protected function setUp(): void
    {
        parent::setUp();
        $this->collections = new CollectionRepository($this->db);
        $this->events      = new EventDispatcher();
        $entries           = new EntryRepository($this->db);
        $this->service     = new EntryService($this->db, $entries, new RelationRepository($this->db), new FieldTypeRegistry(), $this->events);
    }

    /** @param array<string,mixed> $options */
    private function collection(string $handle, array $options = []): Collection
    {
        $options = $options ?: ['kind' => 'collection', 'permissions' => ['manage' => []]];
        $id = (new CollectionService($this->db, $this->collections))->create($handle, ucfirst($handle), '#', '', $options, []);
        return $this->collections->find($id);
    }

    private function entryCount(int $collectionId): int
    {
        return (int) $this->db->selectOne('SELECT COUNT(*) AS c FROM nb_entries WHERE collection_id = :c', ['c' => $collectionId])['c'];
    }

    public function test_singleton_cannot_have_two_entries(): void
    {
        $c = $this->collection('settings', ['kind' => 'single', 'permissions' => ['manage' => []]]);
        $this->service->save($c, new EntryInput('', '', 'draft', []), null, null);
        $this->service->save($c, new EntryInput('', '', 'draft', []), null, null);

        self::assertSame(1, $this->entryCount($c->id));
        $row = $this->db->selectOne('SELECT title, slug FROM nb_entries WHERE collection_id = :c', ['c' => $c->id]);
        self::assertSame(EntryService::SINGLETON_SLUG, $row['slug']);
        self::assertSame('Settings', $row['title']); // auto from collection name
    }

    public function test_slug_is_unique_within_collection_and_collision_is_handled(): void
    {
        $c = $this->collection('posts');
        self::assertTrue($this->service->save($c, new EntryInput('Hello', '', 'draft', []), null, null)->successful);
        self::assertTrue($this->service->save($c, new EntryInput('Hello', '', 'draft', []), null, null)->successful);

        $slugs = array_column($this->db->select('SELECT slug FROM nb_entries WHERE collection_id = :c', ['c' => $c->id]), 'slug');
        self::assertContains('hello', $slugs);
        self::assertContains('hello-2', $slugs);
    }

    public function test_same_slug_allowed_in_different_collections(): void
    {
        $a = $this->collection('news');
        $b = $this->collection('blog');
        $this->service->save($a, new EntryInput('Hello', '', 'draft', []), null, null);
        $this->service->save($b, new EntryInput('Hello', '', 'draft', []), null, null);

        self::assertSame('hello', $this->db->selectOne('SELECT slug FROM nb_entries WHERE collection_id = :c', ['c' => $a->id])['slug']);
        self::assertSame('hello', $this->db->selectOne('SELECT slug FROM nb_entries WHERE collection_id = :c', ['c' => $b->id])['slug']);
    }

    public function test_failed_validation_writes_nothing_and_dispatches_no_events(): void
    {
        $fired = 0;
        $this->events->listen('entry.saved', function () use (&$fired): void {
            $fired++;
        });

        $c = $this->collection('articles');
        $result = $this->service->save($c, new EntryInput('', '', 'draft', []), null, null); // title required, empty

        self::assertFalse($result->successful);
        self::assertSame('invalid', $result->code);
        self::assertArrayHasKey('title', $result->errors);
        self::assertSame('required', $result->errors['title']->code);
        self::assertSame(0, $fired);
        self::assertSame(0, $this->entryCount($c->id));
    }

    public function test_missing_field_type_blocks_the_save_without_touching_data(): void
    {
        // A collection whose field type is provided by a plugin that is no
        // longer installed — the state you are left in after deactivating one.
        $c = $this->collection('places', ['kind' => 'collection', 'permissions' => ['manage' => []]]);
        $this->db->execute(
            "INSERT INTO nb_fields (collection_id, handle, label, type, required, sort, created_at)
             VALUES (:c, 'where', 'Where', 'geolocation', 0, 0, NOW())",
            ['c' => $c->id],
        );
        $c = $this->collections->find($c->id);

        $fired = 0;
        $this->events->listen('entry.saved', function () use (&$fired): void {
            $fired++;
        });

        $result = $this->service->save($c, new EntryInput('Trafalgar Square', '', 'draft', ['where' => '51.5,-0.12']), null, null);

        self::assertFalse($result->successful);
        // A missing provider is a top-level failure, not a per-field error.
        self::assertSame('missing_provider', $result->code);
        self::assertSame([], $result->errors);
        self::assertStringContainsString('geolocation', $result->message);
        self::assertSame(0, $this->entryCount($c->id), 'nothing may be written');
        self::assertSame(0, $fired, 'no events for a refused save');
    }

    public function test_existing_entries_survive_a_missing_field_type(): void
    {
        $c = $this->collection('places', ['kind' => 'collection', 'permissions' => ['manage' => []]]);
        $this->db->execute(
            "INSERT INTO nb_fields (collection_id, handle, label, type, required, sort, created_at)
             VALUES (:c, 'body', 'Body', 'text', 0, 0, NOW())",
            ['c' => $c->id],
        );
        $c = $this->collections->find($c->id);

        // Saved while the type was available...
        self::assertTrue($this->service->save($c, new EntryInput('Kept', '', 'draft', ['body' => 'original']), null, null)->successful);

        // ...then the provider disappears.
        $this->db->execute('UPDATE nb_fields SET type = :t WHERE collection_id = :c', ['t' => 'geolocation', 'c' => $c->id]);
        $c   = $this->collections->find($c->id);
        $row = $this->db->selectOne('SELECT id, data FROM nb_entries WHERE collection_id = :c', ['c' => $c->id]);

        $result = $this->service->save($c, new EntryInput('Overwritten', '', 'draft', ['body' => 'clobbered']), (int) $row['id'], null);

        self::assertFalse($result->successful);
        $after = $this->db->selectOne('SELECT title, data FROM nb_entries WHERE id = :i', ['i' => $row['id']]);
        self::assertSame('Kept', $after['title']);
        self::assertSame($row['data'], $after['data'], 'stored values must be byte-for-byte untouched');
    }

    // ------------------------------------------------------- delete events

    public function test_deleting_a_real_entry_reports_true_and_dispatches(): void
    {
        $fired = [];
        $this->events->listen(CoreEvents::ENTRY_DELETED, function (array $p) use (&$fired): void {
            $fired[] = $p;
        });

        $c  = $this->collection('posts');
        $id = (int) $this->service->save($c, new EntryInput('Doomed', '', 'draft', []), null, null)->entryId;

        self::assertTrue($this->service->delete($c, $id));
        self::assertSame(0, $this->entryCount($c->id));
        self::assertCount(1, $fired);
        self::assertSame($id, $fired[0]['id']);
        self::assertSame($c->id, $fired[0]['collection_id']);
    }

    public function test_deleting_a_missing_entry_reports_false_and_stays_silent(): void
    {
        $fired = 0;
        $this->events->listen(CoreEvents::ENTRY_DELETED, function () use (&$fired): void {
            $fired++;
        });

        $c = $this->collection('posts');

        // Nothing was removed, so nothing may be announced — a listener acting
        // on this would be reacting to a deletion that never happened.
        self::assertFalse($this->service->delete($c, 999999));
        self::assertSame(0, $fired);
    }

    public function test_deleting_the_same_entry_twice_only_announces_once(): void
    {
        $fired = 0;
        $this->events->listen(CoreEvents::ENTRY_DELETED, function () use (&$fired): void {
            $fired++;
        });

        $c  = $this->collection('posts');
        $id = (int) $this->service->save($c, new EntryInput('Doomed', '', 'draft', []), null, null)->entryId;

        self::assertTrue($this->service->delete($c, $id));
        self::assertFalse($this->service->delete($c, $id));
        self::assertSame(1, $fired);
    }

    public function test_deleting_an_entry_from_another_collection_is_a_no_op(): void
    {
        $fired = 0;
        $this->events->listen(CoreEvents::ENTRY_DELETED, function () use (&$fired): void {
            $fired++;
        });

        $posts = $this->collection('posts');
        $pages = $this->collection('pages');
        $id    = (int) $this->service->save($posts, new EntryInput('Safe', '', 'draft', []), null, null)->entryId;

        self::assertFalse($this->service->delete($pages, $id), 'wrong collection must not delete');
        self::assertSame(1, $this->entryCount($posts->id), 'the entry survives');
        self::assertSame(0, $fired);
    }

    public function test_a_throwing_listener_surfaces_rather_than_being_swallowed(): void
    {
        $this->events->listen(CoreEvents::ENTRY_SAVED, function (): void {
            throw new \RuntimeException('listener exploded');
        });

        $c = $this->collection('posts');

        try {
            $this->service->save($c, new EntryInput('Hello', '', 'draft', []), null, null);
            self::fail('a listener exception must propagate to the error boundary');
        } catch (\RuntimeException $e) {
            self::assertSame('listener exploded', $e->getMessage());
        }

        // Dispatch is post-commit, so the write stands even though the listener failed.
        self::assertSame(1, $this->entryCount($c->id));
    }

    // ------------------------------------------------- publication lifecycle

    public function test_publishing_now_makes_an_entry_live(): void
    {
        $c  = $this->collection('posts');
        $id = (int) $this->service->save($c, new EntryInput('Live', '', 'published', []), null, null)->entryId;

        $entries = new EntryRepository($this->db);
        self::assertSame(1, $entries->countLive($c->id));
        self::assertSame($id, (int) $entries->liveForCollection($c->id, 10, 0)[0]['id']);
    }

    public function test_a_draft_is_not_live(): void
    {
        $c = $this->collection('posts');
        $this->service->save($c, new EntryInput('Hidden', '', 'draft', []), null, null);

        self::assertSame(0, (new EntryRepository($this->db))->countLive($c->id));
    }

    public function test_a_scheduled_entry_is_not_live_until_its_time(): void
    {
        $c        = $this->collection('posts');
        $future   = (new \DateTimeImmutable('+2 days'))->format('Y-m-d H:i:s');
        $entries  = new EntryRepository($this->db);

        $id = (int) $this->service->save($c, new EntryInput('Soon', '', 'published', [], $future), null, null)->entryId;

        // Stored as published with a future date — scheduled, not yet live.
        self::assertSame(0, $entries->countLive($c->id), 'a future publish date must not be live');
        $row = $this->db->selectOne('SELECT status, published_at FROM nb_entries WHERE id = :i', ['i' => $id]);
        self::assertSame('published', $row['status']);
        self::assertSame(\Nimbus\Content\Publication::STATE_SCHEDULED, \Nimbus\Content\Publication::state($row['status'], $row['published_at']));
    }

    public function test_a_back_dated_publish_is_immediately_live(): void
    {
        $c    = $this->collection('posts');
        $past = (new \DateTimeImmutable('-2 days'))->format('Y-m-d H:i:s');
        $this->service->save($c, new EntryInput('Backdated', '', 'published', [], $past), null, null);

        self::assertSame(1, (new EntryRepository($this->db))->countLive($c->id));
    }

    public function test_unpublishing_removes_an_entry_from_the_live_set_but_keeps_its_date(): void
    {
        $c       = $this->collection('posts');
        $entries = new EntryRepository($this->db);
        $id      = (int) $this->service->save($c, new EntryInput('On', '', 'published', []), null, null)->entryId;
        self::assertSame(1, $entries->countLive($c->id));

        $originalDate = $entries->publishedAt($c->id, $id);
        $this->service->save($c, new EntryInput('On', 'on', 'draft', []), $id, null);

        self::assertSame(0, $entries->countLive($c->id), 'a draft leaves the live set at once');
        self::assertSame($originalDate, $entries->publishedAt($c->id, $id), 'the publish date is kept for re-publishing');
    }

    public function test_live_lookup_by_slug_ignores_drafts_and_schedules(): void
    {
        $c       = $this->collection('posts');
        $entries = new EntryRepository($this->db);
        $future  = (new \DateTimeImmutable('+1 day'))->format('Y-m-d H:i:s');

        $this->service->save($c, new EntryInput('Public', 'public', 'published', []), null, null);
        $this->service->save($c, new EntryInput('Draft', 'secret', 'draft', []), null, null);
        $this->service->save($c, new EntryInput('Later', 'later', 'published', [], $future), null, null);

        self::assertNotNull($entries->findLiveBySlug($c->id, 'public'));
        self::assertNull($entries->findLiveBySlug($c->id, 'secret'), 'a draft must not be reachable by slug');
        self::assertNull($entries->findLiveBySlug($c->id, 'later'), 'a scheduled entry must not be reachable by slug');
    }

    public function test_live_entries_are_newest_first(): void
    {
        $c       = $this->collection('posts');
        $entries = new EntryRepository($this->db);
        $old     = (new \DateTimeImmutable('-10 days'))->format('Y-m-d H:i:s');
        $recent  = (new \DateTimeImmutable('-1 day'))->format('Y-m-d H:i:s');

        $this->service->save($c, new EntryInput('Older', 'older', 'published', [], $old), null, null);
        $this->service->save($c, new EntryInput('Newer', 'newer', 'published', [], $recent), null, null);

        $slugs = array_column($entries->liveForCollection($c->id, 10, 0), 'slug');
        self::assertSame(['newer', 'older'], $slugs);
    }

    public function test_live_pagination_offsets_correctly(): void
    {
        $c       = $this->collection('posts');
        $entries = new EntryRepository($this->db);
        for ($i = 1; $i <= 5; $i++) {
            $at = (new \DateTimeImmutable("-{$i} days"))->format('Y-m-d H:i:s');
            $this->service->save($c, new EntryInput("Post {$i}", "post-{$i}", 'published', [], $at), null, null);
        }

        self::assertSame(5, $entries->countLive($c->id));
        self::assertCount(2, $entries->liveForCollection($c->id, 2, 0));
        self::assertCount(2, $entries->liveForCollection($c->id, 2, 2));
        self::assertCount(1, $entries->liveForCollection($c->id, 2, 4), 'the last page is partial');
    }

    public function test_successful_save_dispatches_events_after_commit(): void
    {
        $events = [];
        $this->events->listen('entry.created', function () use (&$events): void {
            $events[] = 'created';
        });
        $this->events->listen('entry.saved', function () use (&$events): void {
            $events[] = 'saved';
        });

        $c = $this->collection('pages');
        $result = $this->service->save($c, new EntryInput('About', '', 'published', []), null, null);

        self::assertTrue($result->successful);
        self::assertSame(['created', 'saved'], $events);
        self::assertSame(1, $this->entryCount($c->id));
    }
}

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
use Nimbus\Support\Events;

final class EntryServiceTest extends IntegrationTestCase
{
    private EntryService $service;
    private CollectionRepository $collections;

    protected function setUp(): void
    {
        parent::setUp();
        Events::reset();
        $this->collections = new CollectionRepository($this->db);
        $entries = new EntryRepository($this->db);
        $this->service = new EntryService($this->db, $entries, new RelationRepository($this->db), new FieldTypeRegistry());
    }

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
        Events::listen('entry.saved', function () use (&$fired): void {
            $fired++;
        });

        $c = $this->collection('articles');
        $result = $this->service->save($c, new EntryInput('', '', 'draft', []), null, null); // title required, empty

        self::assertFalse($result->successful);
        self::assertArrayHasKey('__title', $result->errors);
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
            ['c' => $c->id]
        );
        $c = $this->collections->find($c->id);

        $fired = 0;
        Events::listen('entry.saved', function () use (&$fired): void {
            $fired++;
        });

        $result = $this->service->save($c, new EntryInput('Trafalgar Square', '', 'draft', ['where' => '51.5,-0.12']), null, null);

        self::assertFalse($result->successful);
        self::assertArrayHasKey('__types', $result->errors);
        self::assertStringContainsString('geolocation', $result->errors['__types']);
        self::assertSame(0, $this->entryCount($c->id), 'nothing may be written');
        self::assertSame(0, $fired, 'no events for a refused save');
    }

    public function test_existing_entries_survive_a_missing_field_type(): void
    {
        $c = $this->collection('places', ['kind' => 'collection', 'permissions' => ['manage' => []]]);
        $this->db->execute(
            "INSERT INTO nb_fields (collection_id, handle, label, type, required, sort, created_at)
             VALUES (:c, 'body', 'Body', 'text', 0, 0, NOW())",
            ['c' => $c->id]
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

    public function test_successful_save_dispatches_events_after_commit(): void
    {
        $events = [];
        Events::listen('entry.created', function () use (&$events): void {
            $events[] = 'created';
        });
        Events::listen('entry.saved', function () use (&$events): void {
            $events[] = 'saved';
        });

        $c = $this->collection('pages');
        $result = $this->service->save($c, new EntryInput('About', '', 'published', []), null, null);

        self::assertTrue($result->successful);
        self::assertSame(['created', 'saved'], $events);
        self::assertSame(1, $this->entryCount($c->id));
    }
}

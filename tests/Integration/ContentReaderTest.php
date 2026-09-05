<?php

declare(strict_types=1);

namespace Nimbus\Tests\Integration;

use Nimbus\Content\CollectionRepository;
use Nimbus\Content\CollectionService;
use Nimbus\Content\ContentReader;
use Nimbus\Content\EntryInput;
use Nimbus\Content\EntryRepository;
use Nimbus\Content\EntryService;
use Nimbus\Content\FieldTypeRegistry;
use Nimbus\Content\RelationRepository;
use Nimbus\Support\EventDispatcher;

/**
 * The plugin-facing content read (ADR 0029). The load-bearing property is that it
 * exposes only **published** entries — a draft, a scheduled-but-not-due entry, or a
 * wrong-collection id is never surfaced, by list, by id, or by slug — and that the
 * shape matches the public read API (title + expanded fields).
 */
final class ContentReaderTest extends IntegrationTestCase
{
    private ContentReader $reader;
    private EntryService $entries;

    protected function setUp(): void
    {
        parent::setUp();

        $repo = new CollectionRepository($this->db);
        (new CollectionService($this->db, $repo))->create(
            'dishes',
            'Dishes',
            '#',
            '',
            ['kind' => 'collection', 'permissions' => ['manage' => ['editor']]],
            [['handle' => 'price', 'label' => 'Price', 'type' => 'number', 'required' => false, 'options' => []]],
        );

        $this->entries = new EntryService($this->db, new EntryRepository($this->db), new RelationRepository($this->db), new FieldTypeRegistry(), new EventDispatcher());
        $this->reader  = new ContentReader($this->db, new FieldTypeRegistry());
    }

    private function save(string $title, string $slug, string $status, float $price, ?string $publishedAt): int
    {
        $collection = (new CollectionRepository($this->db))->findByHandle('dishes');
        self::assertNotNull($collection);
        $result = $this->entries->save($collection, new EntryInput($title, $slug, $status, ['price' => $price], $publishedAt), null, null);
        self::assertTrue($result->successful, 'entry saved');
        self::assertNotNull($result->entryId);
        return $result->entryId;
    }

    public function test_it_lists_only_published_entries_with_their_fields(): void
    {
        $this->save('Margherita', 'margherita', 'published', 12.5, '2020-01-01 00:00:00');
        $this->save('Soup', 'soup', 'published', 4.0, '2020-01-01 00:00:00');
        $this->save('Secret Special', 'secret', 'draft', 99.0, null);

        $entries = $this->reader->entries('dishes');

        self::assertCount(2, $entries, 'the draft is not listed');
        $titles = array_map(static fn (array $e): string => (string) $e['title'], $entries);
        self::assertContains('Margherita', $titles);
        self::assertContains('Soup', $titles);
        self::assertNotContains('Secret Special', $titles);

        $margherita = array_values(array_filter($entries, static fn (array $e): bool => $e['title'] === 'Margherita'))[0];
        self::assertArrayHasKey('fields', $margherita);
        self::assertEqualsWithDelta(12.5, (float) $margherita['fields']['price'], 0.001, 'the price field is present and shaped like the read API');

        self::assertSame(2, $this->reader->count('dishes'));
    }

    public function test_a_draft_is_invisible_by_id_and_slug(): void
    {
        $draftId = $this->save('Secret Special', 'secret', 'draft', 99.0, null);

        self::assertNull($this->reader->entry('dishes', $draftId), 'a draft is not readable by id');
        self::assertNull($this->reader->entryBySlug('dishes', 'secret'), 'a draft is not readable by slug');
    }

    public function test_a_scheduled_but_not_due_entry_is_invisible(): void
    {
        $futureId = $this->save('Tomorrow Special', 'tomorrow', 'published', 8.0, '2999-01-01 00:00:00');

        self::assertNull($this->reader->entry('dishes', $futureId), 'a future-dated entry is not yet live');
        self::assertSame([], $this->reader->entries('dishes'), 'and it is not listed');
        self::assertSame(0, $this->reader->count('dishes'));
    }

    public function test_a_published_entry_is_readable_by_id_and_slug(): void
    {
        $id = $this->save('Margherita', 'margherita', 'published', 12.5, '2020-01-01 00:00:00');

        $byId = $this->reader->entry('dishes', $id);
        self::assertNotNull($byId);
        self::assertSame('Margherita', $byId['title']);

        $bySlug = $this->reader->entryBySlug('dishes', 'margherita');
        self::assertNotNull($bySlug);
        self::assertSame('Margherita', $bySlug['title']);
    }

    public function test_an_unknown_collection_is_empty_never_an_error(): void
    {
        self::assertSame([], $this->reader->entries('nope'));
        self::assertSame(0, $this->reader->count('nope'));
        self::assertNull($this->reader->entry('nope', 1));
        self::assertNull($this->reader->entryBySlug('nope', 'x'));
    }
}

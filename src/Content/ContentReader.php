<?php

declare(strict_types=1);

namespace Nimbus\Content;

use Nimbus\Database\Connection;
use Nimbus\Media\MediaRepository;

/**
 * A read-only view of **published** collection entries, for plugins (ADR 0029).
 *
 * The plugin boundary deliberately hands a plugin no core services and no service
 * locator ({@see \Nimbus\Plugin\PluginContext}), so an application plugin composing
 * with content — a restaurant reading its menu, a bookings plugin reading its
 * services — had no supported way to read a collection in-process. This is that
 * way, and only that: it exposes exactly what the public read API and themes
 * already expose (published entries, references expanded), and nothing more.
 *
 * It is **read-only and published-only**. Every method carries the same live
 * predicate as the public API ({@see EntryRepository::liveForCollection()} /
 * {@see EntryRepository::findLive()} / {@see EntryRepository::findLiveBySlug()}), so
 * a draft, a scheduled-but-not-due entry, or an entry in another collection is
 * never surfaced. There is no token here (a plugin is trusted, in-process code), so
 * entries come back with references expanded in full — identical to how a theme
 * renders the same content. It cannot write; writes remain {@see EntryService}'s,
 * with its transactions and validation intact.
 *
 * Entries are shaped by {@see EntryView}, so the array a plugin gets is the same
 * shape the read API returns.
 */
final class ContentReader
{
    private CollectionRepository $collections;
    private EntryRepository $entries;
    private EntryView $view;

    public function __construct(Connection $db, FieldTypeRegistry $types)
    {
        $this->collections = new CollectionRepository($db);
        $this->entries     = new EntryRepository($db);
        $this->view        = new EntryView($types, new RelationRepository($db), new MediaRepository($db));
    }

    /**
     * A page of a collection's published entries, newest first — the same order and
     * shape as `GET /api/v1/collections/{handle}/entries`. An unknown handle yields
     * an empty list, never an error.
     *
     * @return list<array<string,mixed>>
     */
    public function entries(string $handle, int $limit = 100, int $offset = 0): array
    {
        $collection = $this->collections->findByHandle($handle);
        if ($collection === null) {
            return [];
        }
        $limit  = max(1, min($limit, 500));
        $offset = max(0, $offset);
        $rows   = $this->entries->liveForCollection($collection->id, $limit, $offset);

        return $this->view->many($collection, $rows);
    }

    /** How many published entries a collection has (0 for an unknown handle). */
    public function count(string $handle): int
    {
        $collection = $this->collections->findByHandle($handle);
        return $collection === null ? 0 : $this->entries->countLive($collection->id);
    }

    /**
     * One published entry by id, or null (unknown handle, or no such published entry
     * in that collection — a draft/scheduled entry reads as absent).
     *
     * @return array<string,mixed>|null
     */
    public function entry(string $handle, int $id): ?array
    {
        $collection = $this->collections->findByHandle($handle);
        if ($collection === null) {
            return null;
        }
        $row = $this->entries->findLive($collection->id, $id);
        return $row === null ? null : $this->view->one($collection, $row);
    }

    /**
     * One published entry by slug, or null — the in-process twin of the public
     * single-entry read.
     *
     * @return array<string,mixed>|null
     */
    public function entryBySlug(string $handle, string $slug): ?array
    {
        $collection = $this->collections->findByHandle($handle);
        if ($collection === null) {
            return null;
        }
        $row = $this->entries->findLiveBySlug($collection->id, $slug);
        return $row === null ? null : $this->view->one($collection, $row);
    }
}

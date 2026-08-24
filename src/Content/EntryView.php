<?php

declare(strict_types=1);

namespace Nimbus\Content;

use Nimbus\Media\MediaItem;
use Nimbus\Media\MediaRepository;

/**
 * The one prepared shape of a stored entry, shared by the read API and (soon)
 * server-rendered themes.
 *
 * Every field value passes through its type's toApi(), so what a consumer sees
 * is the field types' business and never the raw storage layout — the whole
 * point of the toApi() seam. A missing field provider degrades through
 * forDisplay() rather than fatalling the request.
 *
 * This lives in Content, not Api, because it is not an API detail: a theme
 * template renders the same view-model the API serializes, so there is exactly
 * one definition of "an entry, ready to use" for both consumers.
 */
final class EntryView
{
    public function __construct(
        private FieldTypeRegistry $types,
        private RelationRepository $relations,
        private MediaRepository $media,
    ) {
    }

    /**
     * One prepared entry — a single-row {@see many()} (DATA-5), so the two paths
     * share exactly one assembly and can never drift on the DATA-1 guards.
     *
     * @param array<string,mixed> $row a nb_entries row
     * @param ?callable(string):bool $canRead when given, a relation whose target
     *        collection the caller may not read contributes nothing — the same
     *        "leaks nothing" a non-live target already gets. Omit (null) to
     *        expand every relation, for callers that are not scope-limited
     *        (themes, internal use).
     * @return array<string,mixed>
     */
    public function one(Collection $collection, array $row, ?callable $canRead = null): array
    {
        return $this->many($collection, [$row], $canRead)[0];
    }

    /**
     * A page of prepared entries with references expanded in **O(1)** queries,
     * not one per row (DATA-5): every media id on the page resolves in one
     * {@see MediaRepository::findMany()}, and each relation field in one
     * {@see RelationRepository::liveTargetsFor()} — carrying the same DATA-1
     * guards as the per-row path. A relation field whose declared target the
     * caller may not read contributes `[]` for the whole page, and its ids are
     * never even fetched.
     *
     * @param array<int,array<string,mixed>> $rows
     * @param ?callable(string):bool $canRead see one()
     * @return list<array<string,mixed>>
     */
    public function many(Collection $collection, array $rows, ?callable $canRead = null): array
    {
        $rows = array_values($rows);
        if ($rows === []) {
            return [];
        }
        $entryIds = array_map(static fn (array $r): int => (int) $r['id'], $rows);

        // Pool every media id referenced across the page into one lookup.
        $mediaIds = [];
        foreach ($rows as $row) {
            $data = $this->decode($row);
            foreach ($collection->fields as $field) {
                if ($field->type === 'media' && (int) ($data[$field->handle] ?? 0) > 0) {
                    $mediaIds[] = (int) $data[$field->handle];
                }
            }
        }
        $mediaMap = $this->media->findMany($mediaIds);

        // One relation query per relation field (schema-bounded), keyed by
        // from_entry_id — but only for a field whose declared target the caller
        // may read. A denied field is skipped here (zero queries) and project()
        // yields [] for it, so nothing of an unreadable collection is fetched.
        $relMap = [];
        foreach ($collection->fields as $field) {
            if ($field->type !== 'relation') {
                continue;
            }
            $target = (string) $field->option('target', '');
            if ($canRead !== null && !$canRead($target)) {
                continue;
            }
            $relMap[$field->handle] = $this->relations->liveTargetsFor($entryIds, $field->id, $target);
        }

        return array_map(fn (array $row): array => $this->project($collection, $row, $canRead, $relMap, $mediaMap), $rows);
    }

    /**
     * Assemble one entry from the page's pre-fetched lookups. The DATA-1 scope
     * gate lives here, once: a relation field whose declared target the caller
     * may not read contributes `[]` (independent of the integrity/live filter
     * already applied inside the batched query); a media reference resolves
     * against the pooled map (a dangling id — the file was deleted — is absent).
     *
     * @param array<string,mixed> $row
     * @param array<string,array<int,list<array{id:int,slug:string,title:string}>>> $relMap  field handle => (from_entry_id => targets)
     * @param array<int,MediaItem> $mediaMap media id => item
     * @return array<string,mixed>
     */
    private function project(Collection $collection, array $row, ?callable $canRead, array $relMap, array $mediaMap): array
    {
        $id   = (int) $row['id'];
        $data = $this->decode($row);

        $fields = [];
        foreach ($collection->fields as $field) {
            if ($field->type === 'media') {
                $mediaId = (int) ($data[$field->handle] ?? 0);
                $fields[$field->handle] = $mediaId > 0 && isset($mediaMap[$mediaId])
                    ? $this->mediaShape($mediaMap[$mediaId])
                    : null;
                continue;
            }
            if ($field->type === 'relation') {
                $target = (string) $field->option('target', '');
                $fields[$field->handle] = ($canRead !== null && !$canRead($target))
                    ? []
                    : ($relMap[$field->handle][$id] ?? []);
                continue;
            }
            $value = $data[$field->handle] ?? null;
            $fields[$field->handle] = $this->types->forDisplay($field->type)->toApi($field, $value);
        }

        return [
            'id'           => $id,
            'slug'         => (string) $row['slug'],
            'title'        => (string) $row['title'],
            'published_at' => $this->iso($row['published_at'] ?? null),
            'fields'       => $fields,
        ];
    }

    /**
     * Decode a nb_entries row's JSON `data` blob to an array (empty on any
     * non-array).
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function decode(array $row): array
    {
        $data = is_string($row['data'] ?? null) ? json_decode((string) $row['data'], true) : ($row['data'] ?? []);
        return is_array($data) ? $data : [];
    }

    /**
     * The public shape of a media item — the URL and dimensions a consumer needs
     * without a second request. (A dangling/unset reference is handled by the
     * caller: it never reaches here.)
     *
     * @return array<string,mixed>
     */
    private function mediaShape(MediaItem $item): array
    {
        return [
            'id'     => $item->id,
            'url'    => $item->url,
            'alt'    => $item->alt,
            'mime'   => $item->mime,
            'width'  => $item->width,
            'height' => $item->height,
        ];
    }

    private function iso(?string $stored): ?string
    {
        return $stored !== null && $stored !== '' ? (new \DateTimeImmutable($stored))->format(\DateTimeInterface::ATOM) : null;
    }
}

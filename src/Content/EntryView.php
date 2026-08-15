<?php

declare(strict_types=1);

namespace Nimbus\Content;

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
        $id   = (int) $row['id'];
        $data = is_string($row['data'] ?? null) ? json_decode((string) $row['data'], true) : ($row['data'] ?? []);
        $data = is_array($data) ? $data : [];

        $fields = [];
        foreach ($collection->fields as $field) {
            if ($field->type === 'media') {
                // Expand the stored media id to a full, ready-to-use object so a
                // consumer gets the URL without a second request.
                $fields[$field->handle] = $this->media($data[$field->handle] ?? null);
                continue;
            }
            if ($field->type === 'relation') {
                // Relations live in their own table, not the entry's JSON, and
                // expand at this edge to {id,slug,title} objects — the consumer
                // gets a usable link without a second request, and only the live
                // set is exposed (a link to a draft resolves to nothing).
                $target = (string) $field->option('target', '');
                $fields[$field->handle] = ($canRead !== null && !$canRead($target))
                    // Out of the caller's scope: contribute nothing, not even the
                    // targets' existence — exactly as a link to a non-live entry.
                    ? []
                    : $this->relations->liveTargets($id, $field->id);
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
     * @param array<int,array<string,mixed>> $rows
     * @param ?callable(string):bool $canRead see one()
     * @return list<array<string,mixed>>
     */
    public function many(Collection $collection, array $rows, ?callable $canRead = null): array
    {
        return array_map(fn (array $row): array => $this->one($collection, $row, $canRead), array_values($rows));
    }

    /**
     * Expand a stored media id to a public object, or null when unset or the
     * file has since been deleted — a dangling reference reads as absent, it
     * does not error the request.
     *
     * @return array<string,mixed>|null
     */
    private function media(mixed $id): ?array
    {
        if ($id === null || $id === '' || (int) $id <= 0) {
            return null;
        }
        $item = $this->media->find((int) $id);
        if ($item === null) {
            return null;
        }
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

<?php

declare(strict_types=1);

namespace Nimbus\Api;

use Nimbus\Content\Collection;
use Nimbus\Content\FieldTypeRegistry;
use Nimbus\Content\RelationRepository;
use Nimbus\Media\MediaRepository;

/**
 * Turns a stored entry row into the API's public JSON shape.
 *
 * Every field value passes through its type's toApi(), so the API contract is
 * the field types' business and never the raw storage layout — the whole point
 * of the toApi() seam. A missing field provider degrades through forDisplay()
 * rather than fatalling a public request.
 */
final class EntrySerializer
{
    public function __construct(
        private FieldTypeRegistry $types,
        private RelationRepository $relations,
        private MediaRepository $media,
    ) {
    }

    /**
     * @param array<string,mixed> $row a nb_entries row
     * @return array<string,mixed>
     */
    public function one(Collection $collection, array $row): array
    {
        $id   = (int) $row['id'];
        $data = is_string($row['data'] ?? null) ? json_decode((string) $row['data'], true) : ($row['data'] ?? []);
        $data = is_array($data) ? $data : [];

        $fields = [];
        foreach ($collection->fields as $field) {
            if ($field->type === 'media') {
                // Expand the stored media id to a full, ready-to-use object so a
                // client gets the URL without a second request.
                $fields[$field->handle] = $this->media($data[$field->handle] ?? null);
                continue;
            }
            // Relations live in their own table, not the entry's JSON.
            $value = $field->type === 'relation'
                ? $this->relations->targets($id, $field->id)
                : ($data[$field->handle] ?? null);

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
     * @return list<array<string,mixed>>
     */
    public function many(Collection $collection, array $rows): array
    {
        return array_map(fn (array $row): array => $this->one($collection, $row), array_values($rows));
    }

    /**
     * Expand a stored media id to a public object, or null when unset or the
     * file has since been deleted — a dangling reference reads as absent, it
     * does not error a public request.
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

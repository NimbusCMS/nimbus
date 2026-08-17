<?php

declare(strict_types=1);

namespace Nimbus\Content\FieldTypes;

use Nimbus\Content\Field;

/**
 * References a file from the media library. The stored value is a media id (in
 * the entry's JSON); the id is resolved to a full media object at the edges —
 * the entry form renders a picker from library options the controller injects,
 * and the API serializer expands the id to { id, url, alt, … }.
 *
 * Single-value for now: one file per field. Multiple is a deliberate follow-up.
 */
class MediaType extends BaseType
{
    public function type(): string
    {
        return 'media';
    }

    public function label(): string
    {
        return 'Media';
    }

    /** Fallback only — the entry form renders the real picker with library options. */
    public function renderInput(Field $field, mixed $value): string
    {
        return '<input type="hidden" name="' . $this->inputName($field) . '" value="' . $this->e((string) $value) . '">';
    }

    public function renderCell(Field $field, mixed $value): string
    {
        return $value !== null && $value !== '' ? '1 file' : '—';
    }

    /** A media id, or null when nothing is chosen. */
    public function normalize(mixed $input): mixed
    {
        if (is_array($input)) {
            $input = $input[0] ?? null;
        }
        return $input !== null && $input !== '' && (int) $input > 0 ? (int) $input : null;
    }

    /** The raw id; the API serializer expands it to the full media object. */
    public function toApi(Field $field, mixed $value): mixed
    {
        return $value !== null && $value !== '' ? (int) $value : null;
    }

    /** @return array<string,mixed> */
    public function jsonSchema(Field $field): array
    {
        return [
            'type'       => 'object',
            'nullable'   => true,
            'properties' => [
                'id'     => ['type' => 'integer'],
                'url'    => ['type' => 'string'],
                'alt'    => ['type' => 'string', 'nullable' => true],
                'mime'   => ['type' => 'string'],
                'width'  => ['type' => 'integer', 'nullable' => true],
                'height' => ['type' => 'integer', 'nullable' => true],
            ],
        ];
    }
}

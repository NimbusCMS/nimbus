<?php

declare(strict_types=1);

namespace Nimbus\Content\FieldTypes;

use Nimbus\Content\Field;

/**
 * Links an entry to one or many entries in another collection. The value is
 * stored in nb_relations (not the entry JSON), so rendering the picker needs
 * the target entries from the DB — the controller provides those and the entry
 * form renders the control. normalize() here maps a submitted value to int ids.
 */
class RelationType extends BaseType
{
    public function type(): string
    {
        return 'relation';
    }

    public function label(): string
    {
        return 'Relation';
    }

    /** Fallback only — the entry form renders the real picker with DB options. */
    public function renderInput(Field $field, mixed $value): string
    {
        return '<input type="hidden" name="' . $this->inputName($field) . '" value="">';
    }

    public function renderCell(Field $field, mixed $value): string
    {
        $ids = is_array($value) ? $value : [];
        return $ids === [] ? '—' : count($ids) . ' linked';
    }

    /** @return int[] */
    public function normalize(mixed $input): mixed
    {
        if (is_array($input)) {
            return array_values(array_filter(array_map('intval', $input), static fn (int $i): bool => $i > 0));
        }
        return ($input !== null && $input !== '') ? [(int) $input] : [];
    }

    public function toApi(Field $field, mixed $value): mixed
    {
        return is_array($value) ? array_values($value) : [];
    }

    public static function isMultiple(Field $field): bool
    {
        return (bool) $field->option('multiple', false);
    }

    public static function target(Field $field): string
    {
        return (string) $field->option('target', '');
    }

    /** @return array<string,mixed> */
    public function jsonSchema(Field $field): array
    {
        return [
            'type'  => 'array',
            'items' => [
                'type'       => 'object',
                'properties' => [
                    'id'    => ['type' => 'integer'],
                    'slug'  => ['type' => 'string'],
                    'title' => ['type' => 'string'],
                ],
            ],
        ];
    }
}

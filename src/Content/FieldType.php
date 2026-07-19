<?php

declare(strict_types=1);

namespace Nimbus\Content;

/**
 * A field type. Everything the admin + API need to render, list and store a
 * field is defined here — so adding a new type (or shipping one in a plugin) is
 * a single class registered with the FieldTypeRegistry, no core changes.
 */
interface FieldType
{
    /** Stable machine key stored in nb_fields.type, e.g. "text". */
    public function type(): string;

    /** Human label shown in the field-type picker. */
    public function label(): string;

    /** Render the form control for an entry, pre-filled with $value. */
    public function renderInput(Field $field, mixed $value): string;

    /** Render the value as a cell in the entry list. */
    public function renderCell(Field $field, mixed $value): string;

    /** Convert a raw submitted value into what gets stored in the entry JSON. */
    public function normalize(mixed $input): mixed;

    /** Whether this type is configured with a list of choices (like select). */
    public function hasChoices(): bool;
}

<?php

declare(strict_types=1);

namespace Nimbus\Content\FieldTypes;

use Nimbus\Content\Field;

class BooleanType extends BaseType
{
    public function type(): string
    {
        return 'boolean';
    }

    public function label(): string
    {
        return 'Toggle';
    }

    public function renderInput(Field $field, mixed $value): string
    {
        return sprintf(
            '<label class="nb-check"><input type="checkbox" name="%s" value="1"%s> %s</label>',
            $this->inputName($field),
            $value ? ' checked' : '',
            $this->e($field->label),
        );
    }

    public function normalize(mixed $input): mixed
    {
        return $input ? 1 : 0;
    }

    public function renderCell(Field $field, mixed $value): string
    {
        return $value ? '✓' : '—';
    }
}

<?php

declare(strict_types=1);

namespace Nimbus\Content\FieldTypes;

use Nimbus\Content\Field;

class NumberType extends BaseType
{
    public function type(): string
    {
        return 'number';
    }

    public function label(): string
    {
        return 'Number';
    }

    public function renderInput(Field $field, mixed $value): string
    {
        return sprintf(
            '<input type="number" step="any" id="%s" name="%s" value="%s"%s>',
            $this->inputId($field),
            $this->inputName($field),
            $this->e((string) $value),
            $this->required($field),
        );
    }

    public function normalize(mixed $input): mixed
    {
        if (!is_numeric($input)) {
            return null;
        }
        return str_contains((string) $input, '.') ? (float) $input : (int) $input;
    }
}

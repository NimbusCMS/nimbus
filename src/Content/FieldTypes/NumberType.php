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
            '<input type="number" step="any" id="%s" name="%s" value="%s"%s%s>',
            $this->inputId($field),
            $this->inputName($field),
            $this->e((string) $value),
            $this->placeholder($field),
            $this->required($field),
        );
    }

    public function normalize(mixed $input): mixed
    {
        if ($input === null || $input === '') {
            return null;
        }
        // Preserve an invalid submission so validation can reject it, rather than
        // silently erasing it to null (which would look like a blank field).
        if (!is_numeric($input)) {
            return $input;
        }
        return str_contains((string) $input, '.') ? (float) $input : (int) $input;
    }

    public function validate(Field $field, mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null; // empty handled centrally
        }
        return (is_int($value) || is_float($value)) ? null : 'Enter a valid number.';
    }

    /** @return array<string,mixed> */
    public function jsonSchema(Field $field): array
    {
        return ['type' => 'number'];
    }
}

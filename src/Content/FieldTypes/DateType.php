<?php

declare(strict_types=1);

namespace Nimbus\Content\FieldTypes;

use Nimbus\Content\Field;

class DateType extends BaseType
{
    public function type(): string
    {
        return 'date';
    }

    public function label(): string
    {
        return 'Date';
    }

    public function renderInput(Field $field, mixed $value): string
    {
        return sprintf(
            '<input type="date" id="%s" name="%s" value="%s"%s>',
            $this->inputId($field),
            $this->inputName($field),
            $this->e((string) $value),
            $this->required($field),
        );
    }

    public function validate(Field $field, mixed $value): ?string
    {
        $d = \DateTime::createFromFormat('Y-m-d', (string) $value);
        return ($d && $d->format('Y-m-d') === (string) $value) ? null : 'Enter a valid date (YYYY-MM-DD).';
    }

    /** @return array<string,mixed> */
    public function jsonSchema(Field $field): array
    {
        return ['type' => 'string', 'format' => 'date'];
    }
}

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
}

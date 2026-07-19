<?php

declare(strict_types=1);

namespace Nimbus\Content\FieldTypes;

use Nimbus\Content\Field;

class TextareaType extends BaseType
{
    public function type(): string
    {
        return 'textarea';
    }

    public function label(): string
    {
        return 'Text area';
    }

    public function renderInput(Field $field, mixed $value): string
    {
        return sprintf(
            '<textarea id="%s" name="%s" rows="5"%s>%s</textarea>',
            $this->inputId($field),
            $this->inputName($field),
            $this->required($field),
            $this->e((string) $value),
        );
    }
}

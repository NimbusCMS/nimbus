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
            '<textarea id="%s" name="%s" rows="5"%s%s>%s</textarea>',
            $this->inputId($field),
            $this->inputName($field),
            $this->placeholder($field),
            $this->required($field),
            $this->e((string) $value),
        );
    }

    public function validate(Field $field, mixed $value): ?string
    {
        // Generous default for prose, well under max_allowed_packet; a `maxlength`
        // option can lower it. Bounds the JSON `data` column (DoS backstop).
        if (is_string($value) && mb_strlen($value) > ($max = $this->maxLength($field, 50_000))) {
            return "{$field->label} must be {$max} characters or fewer.";
        }
        return null;
    }
}

<?php

declare(strict_types=1);

namespace Nimbus\Content\FieldTypes;

use Nimbus\Content\Field;

class TextType extends BaseType
{
    public function type(): string
    {
        return 'text';
    }

    public function label(): string
    {
        return 'Text';
    }

    /** HTML input type; subtypes (email, url) override. */
    protected function htmlType(): string
    {
        return 'text';
    }

    /** Default single-line length cap; a `maxlength` field option overrides (clamped to the ceiling). */
    protected function defaultMaxLength(): int
    {
        return 255;
    }

    public function validate(Field $field, mixed $value): ?string
    {
        if (is_string($value)) {
            $max = $this->maxLength($field, $this->defaultMaxLength());
            if (mb_strlen($value) > $max) {
                return "{$field->label} must be {$max} characters or fewer.";
            }
        }
        return null;
    }

    public function renderInput(Field $field, mixed $value): string
    {
        return sprintf(
            '<input type="%s" id="%s" name="%s" value="%s"%s%s>',
            $this->htmlType(),
            $this->inputId($field),
            $this->inputName($field),
            $this->e((string) $value),
            $this->placeholder($field),
            $this->required($field),
        );
    }
}

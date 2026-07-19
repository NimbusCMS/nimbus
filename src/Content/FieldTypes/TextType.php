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

    public function renderInput(Field $field, mixed $value): string
    {
        return sprintf(
            '<input type="%s" id="%s" name="%s" value="%s"%s>',
            $this->htmlType(),
            $this->inputId($field),
            $this->inputName($field),
            $this->e((string) $value),
            $this->required($field),
        );
    }
}

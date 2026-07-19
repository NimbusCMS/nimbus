<?php

declare(strict_types=1);

namespace Nimbus\Content\FieldTypes;

use Nimbus\Content\Field;

class SelectType extends BaseType
{
    public function type(): string
    {
        return 'select';
    }

    public function label(): string
    {
        return 'Select';
    }

    public function hasChoices(): bool
    {
        return true;
    }

    public function renderInput(Field $field, mixed $value): string
    {
        $options = '';
        if (!$field->required) {
            $options .= '<option value=""></option>';
        }
        foreach ((array) $field->option('choices', []) as $choice) {
            $choice   = (string) $choice;
            $selected = (string) $value === $choice ? ' selected' : '';
            $options .= sprintf('<option value="%s"%s>%s</option>', $this->e($choice), $selected, $this->e($choice));
        }
        return sprintf(
            '<select id="%s" name="%s"%s>%s</select>',
            $this->inputId($field),
            $this->inputName($field),
            $this->required($field),
            $options,
        );
    }
}

<?php

declare(strict_types=1);

namespace Nimbus\Content\FieldTypes;

use Nimbus\Content\Field;
use Nimbus\Content\FieldType;
use Nimbus\Support\Str;

/** Sensible defaults for field types; concrete types override what differs. */
abstract class BaseType implements FieldType
{
    public function label(): string
    {
        return ucfirst($this->type());
    }

    public function renderCell(Field $field, mixed $value): string
    {
        return $this->e(Str::truncate((string) $value));
    }

    public function normalize(mixed $input): mixed
    {
        return is_scalar($input) ? (string) $input : '';
    }

    public function validate(Field $field, mixed $value): ?string
    {
        return null;
    }

    public function toApi(Field $field, mixed $value): mixed
    {
        return $value;
    }

    public function hasChoices(): bool
    {
        return false;
    }

    /** @return array<string,mixed> */
    public function jsonSchema(Field $field): array
    {
        return ['type' => 'string'];
    }

    protected function inputName(Field $field): string
    {
        return 'f[' . $field->handle . ']';
    }

    protected function inputId(Field $field): string
    {
        return 'f_' . $field->handle;
    }

    protected function required(Field $field): string
    {
        return $field->required ? ' required' : '';
    }

    protected function placeholder(Field $field): string
    {
        $value = (string) $field->option('placeholder', '');
        return $value !== '' ? ' placeholder="' . $this->e($value) . '"' : '';
    }

    protected function e(?string $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

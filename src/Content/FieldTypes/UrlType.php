<?php

declare(strict_types=1);

namespace Nimbus\Content\FieldTypes;

use Nimbus\Content\Field;

class UrlType extends TextType
{
    public function type(): string
    {
        return 'url';
    }

    public function label(): string
    {
        return 'URL';
    }

    protected function htmlType(): string
    {
        return 'url';
    }

    public function validate(\Nimbus\Content\Field $field, mixed $value): ?string
    {
        return filter_var((string) $value, FILTER_VALIDATE_URL) !== false ? null : 'Enter a valid URL (including https://).';
    }

    /** @return array<string,mixed> */
    public function jsonSchema(Field $field): array
    {
        return ['type' => 'string', 'format' => 'uri'];
    }
}

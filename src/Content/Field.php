<?php

declare(strict_types=1);

namespace Nimbus\Content;

/** One field of a collection (a row of nb_fields), as a value object. */
final class Field
{
    /** @param array<string,mixed> $options type-specific config, e.g. select choices */
    public function __construct(
        public readonly string $handle,
        public readonly string $label,
        public readonly string $type,
        public readonly bool $required = false,
        public readonly array $options = [],
        public readonly int $id = 0,
    ) {
    }

    /** @param array<string,mixed> $row */
    public static function fromRow(array $row): self
    {
        $options = [];
        if (!empty($row['options'])) {
            $decoded = json_decode((string) $row['options'], true);
            $options = is_array($decoded) ? $decoded : [];
        }
        return new self(
            (string) $row['handle'],
            (string) $row['label'],
            (string) $row['type'],
            (bool) ($row['required'] ?? false),
            $options,
            (int) ($row['id'] ?? 0),
        );
    }

    public function option(string $key, mixed $default = null): mixed
    {
        return $this->options[$key] ?? $default;
    }
}

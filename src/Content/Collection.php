<?php

declare(strict_types=1);

namespace Nimbus\Content;

/** A content type: nb_collections row hydrated with its fields + options. */
final class Collection
{
    /**
     * @param Field[]              $fields
     * @param array<string,mixed>  $options e.g. ['permissions' => ['manage' => ['editor']]]
     */
    public function __construct(
        public readonly int $id,
        public readonly string $handle,
        public readonly string $name,
        public readonly string $icon,
        public readonly string $description,
        public readonly array $fields = [],
        public readonly array $options = [],
    ) {
    }

    /**
     * @param array<string,mixed> $row
     * @param Field[]             $fields
     */
    public static function fromRow(array $row, array $fields = []): self
    {
        $options = [];
        if (!empty($row['options'])) {
            $decoded = json_decode((string) $row['options'], true);
            $options = is_array($decoded) ? $decoded : [];
        }
        return new self(
            (int) $row['id'],
            (string) $row['handle'],
            (string) $row['name'],
            (string) ($row['icon'] ?? '❑'),
            (string) ($row['description'] ?? ''),
            $fields,
            $options,
        );
    }

    /** @return string[] roles (besides admins) allowed to manage entries */
    public function managerRoles(): array
    {
        $roles = $this->options['permissions']['manage'] ?? [];
        return is_array($roles) ? array_values($roles) : [];
    }

    /** 'collection' (many entries) or 'single' (exactly one — Homepage, Settings). */
    public function kind(): string
    {
        return ($this->options['kind'] ?? 'collection') === 'single' ? 'single' : 'collection';
    }

    public function isSingle(): bool
    {
        return $this->kind() === 'single';
    }

    public function iconChar(): string
    {
        return $this->icon !== '' ? $this->icon : '❑';
    }
}

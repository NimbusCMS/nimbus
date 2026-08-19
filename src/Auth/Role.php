<?php

declare(strict_types=1);

namespace Nimbus\Auth;

/** A named bundle of capabilities (ADR 0011). `isSystem` roles are seeded and un-deletable. */
final readonly class Role
{
    /** @param list<string> $capabilities */
    public function __construct(
        public int $id,
        public string $name,
        public array $capabilities,
        public bool $isSystem,
    ) {
    }

    /** @param array<string,mixed> $row */
    public static function fromRow(array $row): self
    {
        $decoded = json_decode((string) ($row['capabilities'] ?? '[]'), true);
        $caps    = is_array($decoded) ? array_values(array_map('strval', $decoded)) : [];

        return new self(
            (int) $row['id'],
            (string) $row['name'],
            $caps,
            (bool) $row['is_system'],
        );
    }
}

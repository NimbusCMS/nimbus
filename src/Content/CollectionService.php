<?php

declare(strict_types=1);

namespace Nimbus\Content;

use Nimbus\Database\Connection;

/**
 * Transactional writes for a collection + its fields. Creating/updating a
 * collection and synchronizing its fields must succeed or fail together, so
 * they share one transaction boundary.
 */
final class CollectionService
{
    public function __construct(
        private Connection $db,
        private CollectionRepository $collections,
    ) {
    }

    /**
     * @param array<string,mixed> $options
     * @param array<int,FieldDef> $fieldDefs
     */
    public function create(string $handle, string $name, string $icon, string $description, array $options, array $fieldDefs): int
    {
        try {
            return $this->db->transaction(function () use ($handle, $name, $icon, $description, $options, $fieldDefs): int {
                $id = $this->collections->create($handle, $name, $icon, $description, $options);
                $this->collections->syncFields($id, $fieldDefs);
                return $id;
            });
        } catch (\PDOException $e) {
            // The unique index is the authority — a read-then-write check would
            // still let two concurrent creates through.
            if (Connection::isDuplicateKey($e)) {
                throw new DuplicateHandle($handle, $e);
            }
            throw $e;
        }
    }

    /**
     * @param array<string,mixed> $options
     * @param array<int,FieldDef> $fieldDefs
     */
    public function update(int $id, string $name, string $icon, string $description, array $options, array $fieldDefs): void
    {
        $this->db->transaction(function () use ($id, $name, $icon, $description, $options, $fieldDefs): void {
            $this->collections->update($id, $name, $icon, $description, $options);
            $this->collections->syncFields($id, $fieldDefs);
        });
    }

    public function delete(int $id): void
    {
        // Field/entry/relation rows cascade via FKs.
        $this->collections->delete($id);
    }
}

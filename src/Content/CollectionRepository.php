<?php

declare(strict_types=1);

namespace Nimbus\Content;

use Nimbus\Database\Connection;

/** Data access for collections and their fields. */
final class CollectionRepository
{
    public function __construct(private Connection $db)
    {
    }

    /** @return Collection[] all collections (without fields) */
    public function all(): array
    {
        return array_map(
            static fn (array $r): Collection => Collection::fromRow($r),
            $this->db->select('SELECT * FROM nb_collections ORDER BY sort, name')
        );
    }

    public function find(int $id): ?Collection
    {
        $row = $this->db->selectOne('SELECT * FROM nb_collections WHERE id = :id', ['id' => $id]);
        return $row === null ? null : Collection::fromRow($row, $this->fields($id));
    }

    public function findByHandle(string $handle): ?Collection
    {
        $row = $this->db->selectOne('SELECT * FROM nb_collections WHERE handle = :h', ['h' => $handle]);
        return $row === null ? null : Collection::fromRow($row, $this->fields((int) $row['id']));
    }

    public function handleExists(string $handle, int $exceptId = 0): bool
    {
        return $this->db->selectOne(
            'SELECT id FROM nb_collections WHERE handle = :h AND id <> :e',
            ['h' => $handle, 'e' => $exceptId]
        ) !== null;
    }

    /** @return Field[] */
    public function fields(int $collectionId): array
    {
        return array_map(
            static fn (array $r): Field => Field::fromRow($r),
            $this->db->select('SELECT * FROM nb_fields WHERE collection_id = :c ORDER BY sort, id', ['c' => $collectionId])
        );
    }

    public function fieldCount(int $collectionId): int
    {
        return (int) ($this->db->selectOne('SELECT COUNT(*) AS c FROM nb_fields WHERE collection_id = :c', ['c' => $collectionId])['c'] ?? 0);
    }

    public function entryCount(int $collectionId): int
    {
        return (int) ($this->db->selectOne('SELECT COUNT(*) AS c FROM nb_entries WHERE collection_id = :c', ['c' => $collectionId])['c'] ?? 0);
    }

    /** @param array<string,mixed> $options */
    public function create(string $handle, string $name, string $icon, string $description, array $options): int
    {
        $now = date('Y-m-d H:i:s');
        return $this->db->insert(
            'INSERT INTO nb_collections (handle, name, icon, description, options, sort, created_at, updated_at)
             VALUES (:h, :n, :i, :d, :o, 0, :c, :u)',
            ['h' => $handle, 'n' => $name, 'i' => $icon, 'd' => $description, 'o' => json_encode($options), 'c' => $now, 'u' => $now]
        );
    }

    /** @param array<string,mixed> $options */
    public function update(int $id, string $name, string $icon, string $description, array $options): void
    {
        $this->db->execute(
            'UPDATE nb_collections SET name = :n, icon = :i, description = :d, options = :o, updated_at = :u WHERE id = :id',
            ['n' => $name, 'i' => $icon, 'd' => $description, 'o' => json_encode($options), 'u' => date('Y-m-d H:i:s'), 'id' => $id]
        );
    }

    public function delete(int $id): void
    {
        // FK cascade removes fields + entries.
        $this->db->execute('DELETE FROM nb_collections WHERE id = :id', ['id' => $id]);
    }

    /**
     * Reconcile a collection's fields to match the submitted definitions:
     * upsert by handle, delete the rest, preserve order.
     *
     * @param array<int,array{handle:string,label:string,type:string,required:bool,options:array}> $defs
     */
    public function syncFields(int $collectionId, array $defs): void
    {
        $existing = [];
        foreach ($this->db->select('SELECT id, handle FROM nb_fields WHERE collection_id = :c', ['c' => $collectionId]) as $r) {
            $existing[(string) $r['handle']] = (int) $r['id'];
        }

        $now  = date('Y-m-d H:i:s');
        $sort = 0;
        $keep = [];

        foreach ($defs as $def) {
            if ($def['handle'] === '') {
                continue;
            }
            $keep[] = $def['handle'];
            $optionsJson = $def['options'] === [] ? null : json_encode($def['options']);

            if (isset($existing[$def['handle']])) {
                $this->db->execute(
                    'UPDATE nb_fields SET label = :l, type = :t, required = :r, options = :o, sort = :s WHERE id = :id',
                    ['l' => $def['label'], 't' => $def['type'], 'r' => $def['required'] ? 1 : 0, 'o' => $optionsJson, 's' => $sort++, 'id' => $existing[$def['handle']]]
                );
            } else {
                $this->db->execute(
                    'INSERT INTO nb_fields (collection_id, handle, label, type, required, options, sort, created_at)
                     VALUES (:c, :h, :l, :t, :r, :o, :s, :cr)',
                    ['c' => $collectionId, 'h' => $def['handle'], 'l' => $def['label'], 't' => $def['type'], 'r' => $def['required'] ? 1 : 0, 'o' => $optionsJson, 's' => $sort++, 'cr' => $now]
                );
            }
        }

        foreach ($existing as $handle => $id) {
            if (!in_array($handle, $keep, true)) {
                $this->db->execute('DELETE FROM nb_fields WHERE id = :id', ['id' => $id]);
            }
        }
    }
}

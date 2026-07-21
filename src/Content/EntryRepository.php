<?php

declare(strict_types=1);

namespace Nimbus\Content;

use Nimbus\Database\Connection;

/**
 * Data access for entries. Field values live in the JSON `data` column; title,
 * slug and status are promoted to real columns for listing and lookups.
 * Persistence only — the entry lifecycle (transactions, events) belongs to
 * EntryService, so events cannot fire before a commit.
 */
final class EntryRepository
{
    public function __construct(private Connection $db)
    {
    }

    /** @return array<int,array<string,mixed>> hydrated rows (data decoded) */
    public function forCollection(int $collectionId, ?string $search = null): array
    {
        $sql    = 'SELECT * FROM nb_entries WHERE collection_id = :c';
        $params = ['c' => $collectionId];
        if ($search !== null && trim($search) !== '') {
            $sql .= ' AND title LIKE :s';
            $params['s'] = '%' . $search . '%';
        }
        $sql .= ' ORDER BY updated_at DESC';

        return array_map([$this, 'hydrate'], $this->db->select($sql, $params));
    }

    /**
     * id => title for a collection's entries (for relation pickers).
     *
     * @return array<int,string>
     */
    public function titleMap(int $collectionId): array
    {
        $out = [];
        foreach ($this->db->select('SELECT id, title FROM nb_entries WHERE collection_id = :c ORDER BY title', ['c' => $collectionId]) as $r) {
            $out[(int) $r['id']] = (string) $r['title'];
        }
        return $out;
    }

    /**
     * The single entry of a collection (for singletons), or null.
     *
     * @return array<string,mixed>|null
     */
    public function firstForCollection(int $collectionId): ?array
    {
        $row = $this->db->selectOne(
            'SELECT * FROM nb_entries WHERE collection_id = :c ORDER BY id LIMIT 1',
            ['c' => $collectionId]
        );
        return $row === null ? null : $this->hydrate($row);
    }

    /** @return array<string,mixed>|null */
    public function find(int $collectionId, int $id): ?array
    {
        $row = $this->db->selectOne(
            'SELECT * FROM nb_entries WHERE collection_id = :c AND id = :id',
            ['c' => $collectionId, 'id' => $id]
        );
        return $row === null ? null : $this->hydrate($row);
    }

    public function slugExists(int $collectionId, string $slug, int $exceptId = 0): bool
    {
        return $this->db->selectOne(
            'SELECT id FROM nb_entries WHERE collection_id = :c AND slug = :s AND id <> :e',
            ['c' => $collectionId, 's' => $slug, 'e' => $exceptId]
        ) !== null;
    }

    /** @param array{title: string, slug: string, status: string, data: array<string,mixed>} $attrs */
    public function create(int $collectionId, array $attrs, ?int $authorId): int
    {
        $now       = date('Y-m-d H:i:s');
        $published = $attrs['status'] === 'published' ? $now : null;

        return $this->db->insert(
            'INSERT INTO nb_entries (collection_id, title, slug, status, data, author_id, published_at, created_at, updated_at)
             VALUES (:c, :t, :sl, :st, :d, :a, :p, :cr, :u)',
            ['c' => $collectionId, 't' => $attrs['title'], 'sl' => $attrs['slug'], 'st' => $attrs['status'],
             'd' => json_encode($attrs['data'], JSON_THROW_ON_ERROR), 'a' => $authorId, 'p' => $published, 'cr' => $now, 'u' => $now]
        );
    }

    /** @param array{title: string, slug: string, status: string, data: array<string,mixed>} $attrs */
    public function update(int $collectionId, int $id, array $attrs): void
    {
        $now     = date('Y-m-d H:i:s');
        $current = $this->db->selectOne('SELECT published_at FROM nb_entries WHERE id = :id', ['id' => $id]);
        $published = $current['published_at'] ?? null;
        if ($attrs['status'] === 'published' && $published === null) {
            $published = $now;
        }

        $this->db->execute(
            'UPDATE nb_entries SET title = :t, slug = :sl, status = :st, data = :d, published_at = :p, updated_at = :u
             WHERE collection_id = :c AND id = :id',
            ['t' => $attrs['title'], 'sl' => $attrs['slug'], 'st' => $attrs['status'], 'd' => json_encode($attrs['data'], JSON_THROW_ON_ERROR),
             'p' => $published, 'u' => $now, 'c' => $collectionId, 'id' => $id]
        );
    }

    public function delete(int $collectionId, int $id): void
    {
        $this->db->execute('DELETE FROM nb_entries WHERE collection_id = :c AND id = :id', ['c' => $collectionId, 'id' => $id]);
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     * @throws \JsonException when stored JSON is corrupt (never silently emptied)
     */
    private function hydrate(array $row): array
    {
        $raw = $row['data'] ?? null;
        $data = ($raw === null || $raw === '') ? [] : json_decode((string) $raw, true, 512, JSON_THROW_ON_ERROR);
        $row['data'] = is_array($data) ? $data : [];
        return $row;
    }
}

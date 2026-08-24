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
    /**
     * A collection's entries (all statuses), newest-edited first, optionally
     * filtered by a title search. Pass `$limit` to page the result — `$limit`
     * and `$offset` are interpolated as cast ints (they cannot be bound), so the
     * caller must supply integers (the admin derives them from a clamped page).
     * Omit `$limit` for the full set (used where the count is small/bounded).
     *
     * @return array<int,array<string,mixed>> hydrated rows (data decoded)
     */
    public function forCollection(int $collectionId, ?string $search = null, ?int $limit = null, int $offset = 0): array
    {
        $sql    = 'SELECT * FROM nb_entries WHERE collection_id = :c';
        $params = ['c' => $collectionId];
        if ($search !== null && trim($search) !== '') {
            $sql .= ' AND title LIKE :s';
            $params['s'] = '%' . $search . '%';
        }
        $sql .= ' ORDER BY updated_at DESC';
        if ($limit !== null) {
            $sql .= ' LIMIT ' . max(0, $limit) . ' OFFSET ' . max(0, $offset);
        }

        return array_map([$this, 'hydrate'], $this->db->select($sql, $params));
    }

    /** How many entries a collection has, honouring the same title search — for the admin pager total. */
    public function countForCollection(int $collectionId, ?string $search = null): int
    {
        $sql    = 'SELECT COUNT(*) AS c FROM nb_entries WHERE collection_id = :c';
        $params = ['c' => $collectionId];
        if ($search !== null && trim($search) !== '') {
            $sql .= ' AND title LIKE :s';
            $params['s'] = '%' . $search . '%';
        }

        return (int) ($this->db->selectOne($sql, $params)['c'] ?? 0);
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
     * Of the given entry ids, those that belong to collection `$handle` — the
     * membership test behind relation-value integrity (DATA-1): a relation field
     * may only store links into its declared target collection. Membership only,
     * **status-agnostic** (an editor may link a draft; the live filter is applied
     * later, at read/expansion time). An empty input short-circuits (no `IN ()`).
     *
     * @param list<int> $ids
     * @return list<int> the subset that belongs (order not significant — the caller re-imposes submitted order)
     */
    public function idsInCollection(string $handle, array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        // Named placeholders — Connection binds by name (mirrors MediaRepository::findMany).
        $params = ['h' => $handle];
        foreach (array_values($ids) as $i => $id) {
            $params["i{$i}"] = $id;
        }
        $in = implode(',', array_map(static fn (int $i): string => ":i{$i}", array_keys(array_values($ids))));

        return array_values(array_map(
            static fn (array $r): int => (int) $r['id'],
            $this->db->select(
                "SELECT e.id FROM nb_entries e
                 JOIN nb_collections c ON c.id = e.collection_id
                 WHERE c.handle = :h AND e.id IN ({$in})",
                $params,
            ),
        ));
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
            ['c' => $collectionId],
        );
        return $row === null ? null : $this->hydrate($row);
    }

    /** @return array<string,mixed>|null */
    public function find(int $collectionId, int $id): ?array
    {
        $row = $this->db->selectOne(
            'SELECT * FROM nb_entries WHERE collection_id = :c AND id = :id',
            ['c' => $collectionId, 'id' => $id],
        );
        return $row === null ? null : $this->hydrate($row);
    }

    /**
     * An entry by slug regardless of status — the write API's lookup (a client
     * edits drafts too, not just the live set). Hydrated.
     *
     * @return array<string,mixed>|null
     */
    public function findBySlug(int $collectionId, string $slug): ?array
    {
        $row = $this->db->selectOne(
            'SELECT * FROM nb_entries WHERE collection_id = :c AND slug = :s',
            ['c' => $collectionId, 's' => $slug],
        );
        return $row === null ? null : $this->hydrate($row);
    }

    public function slugExists(int $collectionId, string $slug, int $exceptId = 0): bool
    {
        return $this->db->selectOne(
            'SELECT id FROM nb_entries WHERE collection_id = :c AND slug = :s AND id <> :e',
            ['c' => $collectionId, 's' => $slug, 'e' => $exceptId],
        ) !== null;
    }

    /** @param array{title: string, slug: string, status: string, data: array<string,mixed>, published_at: ?string} $attrs */
    public function create(int $collectionId, array $attrs, ?int $authorId): int
    {
        $now = date('Y-m-d H:i:s');

        return $this->db->insert(
            'INSERT INTO nb_entries (collection_id, title, slug, status, data, author_id, published_at, created_at, updated_at)
             VALUES (:c, :t, :sl, :st, :d, :a, :p, :cr, :u)',
            ['c' => $collectionId, 't' => $attrs['title'], 'sl' => $attrs['slug'], 'st' => $attrs['status'],
             'd' => json_encode($attrs['data'], JSON_THROW_ON_ERROR), 'a' => $authorId, 'p' => $attrs['published_at'], 'cr' => $now, 'u' => $now],
        );
    }

    /** @param array{title: string, slug: string, status: string, data: array<string,mixed>, published_at: ?string} $attrs */
    /**
     * @param array<string,mixed> $attrs
     * @param ?int $expectedVersion when non-null, an atomic compare-and-swap:
     *   the UPDATE only applies if the row is still at this version, else it
     *   throws {@see EntryConcurrencyConflict} (rowCount 0). The version+1 in the
     *   SET clause guarantees a matched row always *changes*, so rowCount reliably
     *   reflects a match even without MYSQL_ATTR_FOUND_ROWS — do not add a
     *   "skip write when unchanged" optimization without revisiting this.
     */
    public function update(int $collectionId, int $id, array $attrs, ?int $expectedVersion = null): void
    {
        $now = date('Y-m-d H:i:s');

        // version bumps on every update — it is the entry's optimistic-concurrency
        // token (ADR 0007), surfaced as the API's ETag.
        $sql    = 'UPDATE nb_entries SET title = :t, slug = :sl, status = :st, data = :d, published_at = :p, updated_at = :u, version = version + 1
             WHERE collection_id = :c AND id = :id';
        $params = ['t' => $attrs['title'], 'sl' => $attrs['slug'], 'st' => $attrs['status'], 'd' => json_encode($attrs['data'], JSON_THROW_ON_ERROR),
            'p' => $attrs['published_at'], 'u' => $now, 'c' => $collectionId, 'id' => $id];
        if ($expectedVersion !== null) {
            $sql .= ' AND version = :ev';
            $params['ev'] = $expectedVersion;
        }

        $affected = $this->db->execute($sql, $params);
        if ($expectedVersion !== null && $affected === 0) {
            throw new EntryConcurrencyConflict();
        }
    }

    /** The published_at currently stored for an entry, or null. */
    public function publishedAt(int $collectionId, int $id): ?string
    {
        $row = $this->db->selectOne('SELECT published_at FROM nb_entries WHERE collection_id = :c AND id = :id', ['c' => $collectionId, 'id' => $id]);
        return $row['published_at'] ?? null;
    }

    /**
     * Live entries of a collection, newest first — the public set the API
     * serves. "Live" is the single predicate from Publication: published, with
     * a publish time that has arrived.
     *
     * @return array<int,array<string,mixed>>
     */
    public function liveForCollection(int $collectionId, int $limit, int $offset): array
    {
        return $this->db->select(
            "SELECT * FROM nb_entries
             WHERE collection_id = :c AND status = 'published' AND published_at IS NOT NULL AND published_at <= NOW()
             ORDER BY published_at DESC, id DESC
             LIMIT {$limit} OFFSET {$offset}",
            ['c' => $collectionId],
        );
    }

    /** How many live entries a collection has — for API pagination totals. */
    public function countLive(int $collectionId): int
    {
        return (int) $this->db->selectOne(
            "SELECT COUNT(*) AS c FROM nb_entries
             WHERE collection_id = :c AND status = 'published' AND published_at IS NOT NULL AND published_at <= NOW()",
            ['c' => $collectionId],
        )['c'];
    }

    /**
     * A single live entry by slug, or null — the public single-entry lookup.
     *
     * @return array<string,mixed>|null
     */
    public function findLiveBySlug(int $collectionId, string $slug): ?array
    {
        return $this->db->selectOne(
            "SELECT * FROM nb_entries
             WHERE collection_id = :c AND slug = :s AND status = 'published' AND published_at IS NOT NULL AND published_at <= NOW()",
            ['c' => $collectionId, 's' => $slug],
        );
    }

    /** @return int rows removed — 0 when the entry was absent or belongs to another collection */
    /**
     * @param ?int $expectedVersion when non-null, a compare-and-swap delete: it
     *   removes the row only if still at this version, else throws
     *   {@see EntryConcurrencyConflict} (rowCount 0). rowCount 0 conflates
     *   "version changed" and "already gone" — both are correctly a 412 under
     *   If-Match (no current representation), so do not "fix" one into a 404.
     */
    public function delete(int $collectionId, int $id, ?int $expectedVersion = null): int
    {
        $sql    = 'DELETE FROM nb_entries WHERE collection_id = :c AND id = :id';
        $params = ['c' => $collectionId, 'id' => $id];
        if ($expectedVersion !== null) {
            $sql .= ' AND version = :ev';
            $params['ev'] = $expectedVersion;
        }

        $affected = $this->db->execute($sql, $params);
        if ($expectedVersion !== null && $affected === 0) {
            throw new EntryConcurrencyConflict();
        }
        return $affected;
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

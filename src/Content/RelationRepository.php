<?php

declare(strict_types=1);

namespace Nimbus\Content;

use Nimbus\Database\Connection;

/**
 * Data access for the nb_relations table. A relation field's value lives here
 * (not in the entry JSON), which is what gives us reverse lookups + referential
 * integrity.
 */
final class RelationRepository
{
    public function __construct(private Connection $db)
    {
    }

    /**
     * Target entry ids for one entry's relation field, in order.
     *
     * @return int[]
     */
    public function targets(int $fromEntryId, int $fieldId): array
    {
        return array_map(
            static fn (array $r): int => (int) $r['to_entry_id'],
            $this->db->select(
                'SELECT to_entry_id FROM nb_relations WHERE from_entry_id = :f AND field_id = :fl ORDER BY sort, id',
                ['f' => $fromEntryId, 'fl' => $fieldId],
            ),
        );
    }

    /**
     * Live target entries for one entry's relation field, in link order —
     * enough to render or link to each without a second request.
     *
     * Only the live set is returned: a relation pointing at a draft, a
     * not-yet-due scheduled entry, or an archived one contributes nothing,
     * exactly as that entry is absent from the public API. A relation must
     * never leak an unpublished entry's slug or title. "Live" is the same
     * predicate the entry queries use (published, publish time arrived).
     *
     * Also constrained to `$targetHandle` — the field's declared target
     * collection (DATA-1): a stored link into some other collection (a legacy
     * row, or one written after the field was retargeted) expands to nothing, so
     * a `posts` relation only ever yields `posts` entries. The handle is
     * **required** (an empty handle matches no collection → nothing): there is no
     * "unfiltered" mode, so no caller can accidentally re-open the leak.
     *
     * @return list<array{id:int,slug:string,title:string}>
     */
    public function liveTargets(int $fromEntryId, int $fieldId, string $targetHandle): array
    {
        $rows = $this->db->select(
            "SELECT e.id, e.slug, e.title
             FROM nb_relations r
             JOIN nb_entries e ON e.id = r.to_entry_id
             JOIN nb_collections c ON c.id = e.collection_id
             WHERE r.from_entry_id = :f AND r.field_id = :fl AND c.handle = :target
               AND e.status = 'published' AND e.published_at IS NOT NULL AND e.published_at <= NOW()
             ORDER BY r.sort, r.id",
            ['f' => $fromEntryId, 'fl' => $fieldId, 'target' => $targetHandle],
        );

        return array_values(array_map(
            static fn (array $r): array => [
                'id'    => (int) $r['id'],
                'slug'  => (string) $r['slug'],
                'title' => (string) $r['title'],
            ],
            $rows,
        ));
    }

    /**
     * Replace the links for one entry's relation field.
     *
     * INVARIANT: `$toIds` MUST already be constrained to the field's declared
     * target collection — this primitive does not check membership. The single
     * caller ({@see \Nimbus\Content\EntryService::save}) filters via
     * `EntryRepository::idsInCollection` first (DATA-1). `liveTargets` re-imposes
     * the constraint at read time as defense-in-depth.
     *
     * @param int[] $toIds
     */
    public function sync(int $fromEntryId, int $fieldId, array $toIds): void
    {
        $this->db->execute(
            'DELETE FROM nb_relations WHERE from_entry_id = :f AND field_id = :fl',
            ['f' => $fromEntryId, 'fl' => $fieldId],
        );
        $now  = date('Y-m-d H:i:s');
        $sort = 0;
        foreach ($toIds as $to) {
            $to = (int) $to;
            if ($to <= 0) {
                continue;
            }
            $this->db->insert(
                'INSERT INTO nb_relations (from_entry_id, field_id, to_entry_id, sort, created_at) VALUES (:f, :fl, :t, :s, :c)',
                ['f' => $fromEntryId, 'fl' => $fieldId, 't' => $to, 's' => $sort++, 'c' => $now],
            );
        }
    }

    /**
     * Reverse lookup: entries that link TO a given entry ("where used").
     *
     * @return array<int,array<string,mixed>>
     */
    public function incoming(int $toEntryId): array
    {
        return $this->db->select(
            'SELECT from_entry_id, field_id FROM nb_relations WHERE to_entry_id = :t',
            ['t' => $toEntryId],
        );
    }
}

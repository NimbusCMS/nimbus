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
     * Replace the links for one entry's relation field.
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

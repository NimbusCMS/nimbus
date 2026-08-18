<?php

declare(strict_types=1);

namespace Nimbus\Media;

use Nimbus\Database\Connection;

/**
 * The reverse index of media references (nb_media_usage). It answers "where is
 * this file used?" cheaply, and is kept in step by EntryService on every save
 * — the same way relations are synced. It backs the delete guard so no code
 * path (admin, API, MCP) can silently orphan an in-use file.
 */
final class MediaUsageRepository
{
    public function __construct(private Connection $db)
    {
    }

    /**
     * Replace an entry's usage rows with the media ids it now references, by
     * field. Delete-then-insert, inside the caller's save transaction, so the
     * index can never drift from the entry's stored values.
     *
     * @param array<int,int[]> $byField media ids per field id
     */
    public function sync(int $entryId, array $byField): void
    {
        $this->db->execute('DELETE FROM nb_media_usage WHERE entry_id = :e', ['e' => $entryId]);

        $now = date('Y-m-d H:i:s');
        foreach ($byField as $fieldId => $mediaIds) {
            foreach (array_unique(array_filter($mediaIds, static fn (int $i): bool => $i > 0)) as $mediaId) {
                $this->db->execute(
                    'INSERT INTO nb_media_usage (media_id, entry_id, field_id, created_at) VALUES (:m, :e, :f, :c)',
                    ['m' => $mediaId, 'e' => $entryId, 'f' => $fieldId, 'c' => $now],
                );
            }
        }
    }

    /** Is this media item referenced anywhere? */
    public function isUsed(int $mediaId): bool
    {
        return $this->db->selectOne('SELECT 1 FROM nb_media_usage WHERE media_id = :m LIMIT 1', ['m' => $mediaId]) !== null;
    }

    /**
     * Every place a media item is used, human-readable enough to pinpoint —
     * collection + entry (title/slug) + the field that references it.
     *
     * @return list<array{collection:string,entry_id:int,entry_title:string,entry_slug:string,field_handle:string,field_label:string}>
     */
    public function usageOf(int $mediaId): array
    {
        /** @var list<array{collection:string,entry_id:int,entry_title:string,entry_slug:string,field_handle:string,field_label:string}> $rows */
        $rows = $this->db->select(
            'SELECT c.handle AS collection, e.id AS entry_id, e.title AS entry_title, e.slug AS entry_slug,
                    f.handle AS field_handle, f.label AS field_label
             FROM nb_media_usage u
             JOIN nb_entries e     ON e.id = u.entry_id
             JOIN nb_collections c ON c.id = e.collection_id
             JOIN nb_fields f      ON f.id = u.field_id
             WHERE u.media_id = :m
             ORDER BY c.handle, e.title, f.handle',
            ['m' => $mediaId],
        );
        return $rows;
    }
}

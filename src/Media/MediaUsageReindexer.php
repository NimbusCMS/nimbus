<?php

declare(strict_types=1);

namespace Nimbus\Media;

use Nimbus\Content\CollectionRepository;
use Nimbus\Database\Connection;

/**
 * Rebuilds the media-usage index from current entry data — the backfill for
 * content that existed before the index did, run once on upgrade via
 * `nimbus media:reindex`. New writes keep the index current on their own
 * (EntryService), so this is a one-off, not a routine job.
 */
final class MediaUsageReindexer
{
    public function __construct(
        private Connection $db,
        private CollectionRepository $collections,
        private MediaUsageRepository $usage,
    ) {
    }

    /** Rebuild usage for every entry; returns the number of entries scanned. */
    public function reindex(): int
    {
        // collection id => [field handle => field id] for its media fields.
        $mediaFields = [];
        foreach ($this->collections->all() as $collection) {
            $fields = [];
            foreach ($this->collections->fields($collection->id) as $field) {
                if ($field->type === 'media') {
                    $fields[$field->handle] = $field->id;
                }
            }
            if ($fields !== []) {
                $mediaFields[$collection->id] = $fields;
            }
        }

        $scanned = 0;
        foreach ($this->db->select('SELECT id, collection_id, data FROM nb_entries') as $row) {
            $fields  = $mediaFields[(int) $row['collection_id']] ?? [];
            $byField = [];
            if ($fields !== []) {
                $decoded = is_string($row['data'] ?? null) ? json_decode($row['data'], true) : [];
                $data    = is_array($decoded) ? $decoded : [];
                foreach ($fields as $handle => $fieldId) {
                    $value = $data[$handle] ?? null;
                    $id    = is_numeric($value) ? (int) $value : 0;
                    if ($id > 0) {
                        $byField[$fieldId] = [$id];
                    }
                }
            }
            $this->usage->sync((int) $row['id'], $byField);
            $scanned++;
        }
        return $scanned;
    }
}

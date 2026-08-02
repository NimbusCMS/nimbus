<?php

declare(strict_types=1);

/**
 * Index for the "live" query the API leans on:
 *   status = 'published' AND published_at <= NOW()
 * scoped per collection. The existing idx_entry_status covers (collection_id,
 * status); this extends it with published_at so the range check is indexed too.
 *
 * See docs/adr/0002-publication-lifecycle.md. No column changes: status is
 * already VARCHAR and published_at already exists — the lifecycle needed a
 * definition and an index, not new storage.
 */

return [
    'CREATE INDEX idx_entry_live ON nb_entries (collection_id, status, published_at)',
];

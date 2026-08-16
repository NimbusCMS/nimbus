<?php

declare(strict_types=1);

/**
 * A monotonic version per entry, bumped on every save (EntryService). It backs
 * the write API's optimistic concurrency (ADR 0007): a read returns it as a
 * strong ETag, and a write must present a matching If-Match to proceed, so two
 * machine clients cannot silently clobber each other.
 *
 * Existing rows start at 1; new rows default to 1.
 */

return [
    'ALTER TABLE nb_entries ADD COLUMN version INT UNSIGNED NOT NULL DEFAULT 1 AFTER status',
];

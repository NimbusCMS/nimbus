<?php

declare(strict_types=1);

/**
 * A reverse index of where each media item is used, so "is this file in use?"
 * is a fast, reliable query instead of scanning every entry's JSON. A row means:
 * this media item is referenced by this entry's media field.
 *
 * Synced by EntryService on every save (mirroring nb_relations), it backs the
 * delete guard — the admin, the API and MCP all refuse to delete media that is
 * still referenced, and can pinpoint where. "Used" means referenced by a media
 * *field* (structured + indexable); a raw URL pasted into freetext is out of
 * scope on purpose. The entry/field FK cascades keep it clean: deleting an entry
 * or removing a field automatically frees the media it referenced.
 *
 * media_id is deliberately NOT a foreign key: an entry may hold a *dangling*
 * media id (a file deleted out-of-band, or an imported reference), and indexing
 * that must never fail a save. The delete guard doesn't need the FK — it looks
 * media up by id directly — and the entry/field FKs give the cascades that
 * matter.
 */
return [
    'CREATE TABLE nb_media_usage (
        id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        media_id   INT UNSIGNED NOT NULL,
        entry_id   INT UNSIGNED NOT NULL,
        field_id   INT UNSIGNED NOT NULL,
        created_at DATETIME NOT NULL,
        UNIQUE KEY uq_media_usage (media_id, entry_id, field_id),
        KEY idx_mu_media (media_id),
        KEY idx_mu_entry (entry_id),
        CONSTRAINT fk_mu_entry FOREIGN KEY (entry_id) REFERENCES nb_entries (id) ON DELETE CASCADE,
        CONSTRAINT fk_mu_field FOREIGN KEY (field_id) REFERENCES nb_fields  (id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
];

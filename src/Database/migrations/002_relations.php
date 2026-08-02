<?php

declare(strict_types=1);

/**
 * Relations live in their own table (not embedded in entry JSON) so we get
 * referential integrity, reverse lookups ("what links to this entry?") and
 * efficient queries. A row means: from_entry's relation field -> to_entry.
 */
return [
    'CREATE TABLE nb_relations (
        id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        from_entry_id INT UNSIGNED NOT NULL,
        field_id      INT UNSIGNED NOT NULL,
        to_entry_id   INT UNSIGNED NOT NULL,
        sort          INT NOT NULL DEFAULT 0,
        created_at    DATETIME NOT NULL,
        KEY idx_rel_from (from_entry_id, field_id),
        KEY idx_rel_to (to_entry_id),
        CONSTRAINT fk_rel_from  FOREIGN KEY (from_entry_id) REFERENCES nb_entries (id) ON DELETE CASCADE,
        CONSTRAINT fk_rel_field FOREIGN KEY (field_id)      REFERENCES nb_fields  (id) ON DELETE CASCADE,
        CONSTRAINT fk_rel_to    FOREIGN KEY (to_entry_id)   REFERENCES nb_entries (id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
];

<?php

declare(strict_types=1);

/**
 * Editable navigation menus (the admin Menus editor).
 *
 * Menus were config-only (`config/menus.php`); this DB store lets an admin edit
 * them — exactly as `nb_settings` overrides file-default settings. A row overrides
 * the file default for that menu name; the file stays the seed/fallback. Items are
 * a JSON list of `{label, url}`; URLs are scheme-validated before they're stored
 * (and again on read) so a menu link can never carry a `javascript:` payload.
 */
return [
    'CREATE TABLE nb_menus (
        name       VARCHAR(64) NOT NULL PRIMARY KEY,
        items      JSON NOT NULL,
        updated_at DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
];

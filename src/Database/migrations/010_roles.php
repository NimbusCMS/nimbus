<?php

declare(strict_types=1);

/**
 * Roles (ADR 0011): a role is a named bundle of capabilities from the one
 * vocabulary shared by users and tokens. Users are assigned one or more roles
 * (their authority is the union), so authority is least-privilege instead of
 * three fixed roles. `is_system` marks the seeded admin/editor/author roles.
 *
 * The seed (system roles + user assignments + folding collection manage-lists
 * into capabilities) is data-dependent, so it runs in PHP via `nimbus roles:seed`
 * (also called by `install`), not here.
 */
return [
    'CREATE TABLE nb_roles (
        id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name         VARCHAR(80) NOT NULL UNIQUE,
        capabilities JSON NOT NULL,
        is_system    TINYINT(1) NOT NULL DEFAULT 0,
        created_at   DATETIME NOT NULL,
        updated_at   DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',

    'CREATE TABLE nb_user_roles (
        user_id INT UNSIGNED NOT NULL,
        role_id INT UNSIGNED NOT NULL,
        PRIMARY KEY (user_id, role_id),
        KEY idx_ur_role (role_id),
        CONSTRAINT fk_ur_user FOREIGN KEY (user_id) REFERENCES nb_users (id) ON DELETE CASCADE,
        CONSTRAINT fk_ur_role FOREIGN KEY (role_id) REFERENCES nb_roles (id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
];

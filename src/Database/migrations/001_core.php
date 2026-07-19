<?php

declare(strict_types=1);

/**
 * Core NimbusCMS schema. All platform tables are prefixed nb_ and store flexible
 * content in JSON columns (MySQL 8), which keeps the schema stable while letting
 * collections define arbitrary fields.
 */
return [
    // ---- users ----
    "CREATE TABLE nb_users (
        id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name         VARCHAR(120) NOT NULL,
        email        VARCHAR(191) NOT NULL UNIQUE,
        password     VARCHAR(255) NOT NULL,
        role         VARCHAR(40)  NOT NULL DEFAULT 'editor',
        avatar_url   VARCHAR(255) NULL,
        theme        VARCHAR(40)  NULL,
        created_at   DATETIME NOT NULL,
        updated_at   DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    // ---- settings (key/value) ----
    "CREATE TABLE nb_settings (
        `key`   VARCHAR(120) NOT NULL PRIMARY KEY,
        `value` TEXT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    // ---- collections (content types) ----
    "CREATE TABLE nb_collections (
        id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        handle      VARCHAR(80)  NOT NULL UNIQUE,
        name        VARCHAR(120) NOT NULL,
        icon        VARCHAR(40)  NULL,
        description VARCHAR(255) NULL,
        options     JSON NULL,
        sort        INT NOT NULL DEFAULT 0,
        created_at  DATETIME NOT NULL,
        updated_at  DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    // ---- fields (belong to a collection) ----
    "CREATE TABLE nb_fields (
        id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        collection_id INT UNSIGNED NOT NULL,
        handle        VARCHAR(80)  NOT NULL,
        label         VARCHAR(120) NOT NULL,
        type          VARCHAR(40)  NOT NULL DEFAULT 'text',
        options       JSON NULL,
        required      TINYINT(1) NOT NULL DEFAULT 0,
        sort          INT NOT NULL DEFAULT 0,
        created_at    DATETIME NOT NULL,
        UNIQUE KEY uq_field (collection_id, handle),
        CONSTRAINT fk_field_collection FOREIGN KEY (collection_id) REFERENCES nb_collections (id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    // ---- entries (content records) ----
    "CREATE TABLE nb_entries (
        id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        collection_id INT UNSIGNED NOT NULL,
        title         VARCHAR(255) NOT NULL DEFAULT '',
        slug          VARCHAR(191) NOT NULL,
        status        VARCHAR(20)  NOT NULL DEFAULT 'draft',
        data          JSON NULL,
        author_id     INT UNSIGNED NULL,
        published_at  DATETIME NULL,
        created_at    DATETIME NOT NULL,
        updated_at    DATETIME NOT NULL,
        UNIQUE KEY uq_entry_slug (collection_id, slug),
        KEY idx_entry_status (collection_id, status),
        CONSTRAINT fk_entry_collection FOREIGN KEY (collection_id) REFERENCES nb_collections (id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    // ---- revisions (entry history) ----
    "CREATE TABLE nb_revisions (
        id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        entry_id   INT UNSIGNED NOT NULL,
        title      VARCHAR(255) NOT NULL DEFAULT '',
        status     VARCHAR(20)  NOT NULL DEFAULT 'draft',
        data       JSON NULL,
        author_id  INT UNSIGNED NULL,
        created_at DATETIME NOT NULL,
        KEY idx_rev_entry (entry_id),
        CONSTRAINT fk_rev_entry FOREIGN KEY (entry_id) REFERENCES nb_entries (id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    // ---- media library ----
    "CREATE TABLE nb_media (
        id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        filename   VARCHAR(255) NOT NULL,
        path       VARCHAR(255) NOT NULL,
        url        VARCHAR(255) NOT NULL,
        mime       VARCHAR(120) NOT NULL,
        size       INT UNSIGNED NOT NULL DEFAULT 0,
        width      INT UNSIGNED NULL,
        height     INT UNSIGNED NULL,
        alt        VARCHAR(255) NULL,
        title      VARCHAR(255) NULL,
        author_id  INT UNSIGNED NULL,
        created_at DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    // ---- activity log ----
    "CREATE TABLE nb_activity (
        id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id      INT UNSIGNED NULL,
        action       VARCHAR(40)  NOT NULL,
        subject_type VARCHAR(40)  NULL,
        subject_id   INT UNSIGNED NULL,
        summary      VARCHAR(255) NULL,
        created_at   DATETIME NOT NULL,
        KEY idx_activity_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    // ---- API tokens (headless access) ----
    "CREATE TABLE nb_api_tokens (
        id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name         VARCHAR(120) NOT NULL,
        token_hash   VARCHAR(255) NOT NULL UNIQUE,
        abilities    JSON NULL,
        last_used_at DATETIME NULL,
        created_at   DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
];

<?php

declare(strict_types=1);

/**
 * Entry-scoped, short-lived draft-preview tokens (ADR 0021).
 *
 * A preview link exposes ONE unpublished entry to whoever holds the token, for a
 * short window — so, exactly like nb_password_resets / nb_api_tokens, only the
 * SHA-256 **hash** of the high-entropy token is stored (a DB read never yields a
 * usable link). The token binds `collection_id` + `entry_id`, so it can only ever
 * reveal that one entry; `expires_at` bounds its life; the FK cascade drops a
 * token when its entry is deleted.
 */
return [
    'CREATE TABLE nb_preview_tokens (
        id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        collection_id INT UNSIGNED NOT NULL,
        entry_id      INT UNSIGNED NOT NULL,
        token_hash    CHAR(64) NOT NULL,
        created_by    INT UNSIGNED NULL,
        expires_at    DATETIME NOT NULL,
        created_at    DATETIME NOT NULL,
        UNIQUE KEY uq_preview_token (token_hash),
        KEY idx_preview_entry (collection_id, entry_id),
        CONSTRAINT fk_preview_entry FOREIGN KEY (entry_id) REFERENCES nb_entries (id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
];

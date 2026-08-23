<?php

declare(strict_types=1);

/**
 * External identity links for SSO (ADR 0012). A row means: this Nimbus user has
 * proven control of this identity at this provider. Keyed by the **immutable
 * provider subject** (Google `sub`, GitHub numeric `id`) — never by email, which
 * changes and reassigns. `UNIQUE(provider, provider_user_id)` makes an identity
 * belong to exactly one user (no silent account theft); the FK cascade drops a
 * user's links when the user is deleted. No provider tokens are stored — identity
 * is read at login only.
 */
return [
    'CREATE TABLE nb_oauth_identities (
        id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id          INT UNSIGNED NOT NULL,
        provider         VARCHAR(40)  NOT NULL,
        provider_user_id VARCHAR(191) NOT NULL,
        email            VARCHAR(191) NULL,
        created_at       DATETIME NOT NULL,
        UNIQUE KEY uq_oauth_identity (provider, provider_user_id),
        KEY idx_oauth_user (user_id),
        CONSTRAINT fk_oauth_user FOREIGN KEY (user_id) REFERENCES nb_users (id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
];

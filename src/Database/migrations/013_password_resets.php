<?php

declare(strict_types=1);

/**
 * One-time, expiring password-reset tokens (self-service reset via emailed link).
 *
 * Only the SHA-256 **hash** of the high-entropy token is stored — the plaintext
 * lives only in the emailed link (mirroring nb_api_tokens: a random secret has
 * nothing to brute-force, so a fast hash is right, and a DB read never yields a
 * usable token). `used_at` makes a token single-use, `expires_at` bounds its
 * life; the FK cascade drops a user's tokens when the user is deleted.
 */
return [
    'CREATE TABLE nb_password_resets (
        id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id    INT UNSIGNED NOT NULL,
        token_hash CHAR(64) NOT NULL,
        expires_at DATETIME NOT NULL,
        used_at    DATETIME NULL,
        created_at DATETIME NOT NULL,
        UNIQUE KEY uq_reset_token (token_hash),
        KEY idx_reset_user (user_id),
        CONSTRAINT fk_reset_user FOREIGN KEY (user_id) REFERENCES nb_users (id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
];

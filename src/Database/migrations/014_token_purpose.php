<?php

declare(strict_types=1);

/**
 * The one-time token table now backs two flows — password **reset** and user
 * **invitation** — so a `purpose` distinguishes them. Every lookup, consume and
 * invalidation filters by purpose, so the two classes are never interchangeable
 * (an invite token can't be spent as a reset, and issuing one never disturbs the
 * other). Existing rows are resets; new invites carry `invite`.
 */
return [
    "ALTER TABLE nb_password_resets
        ADD COLUMN purpose VARCHAR(20) NOT NULL DEFAULT 'reset' AFTER user_id",
];

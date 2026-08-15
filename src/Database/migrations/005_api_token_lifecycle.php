<?php

declare(strict_types=1);

/**
 * API token lifecycle: expiry, permanent revocation, and a reversible paused
 * state, plus a small bounded usage record (a count and the last IP) the token
 * management surfaces read at a glance. See
 * docs/adr/0006-non-human-authentication.md.
 *
 * A token minted before this migration gets NULL across the lifecycle columns
 * and 0 for used_count — immortal, active, never-used — so nothing changes for
 * an existing token. The 45-char IP column holds a full IPv6 address.
 */

return [
    'ALTER TABLE nb_api_tokens
        ADD COLUMN expires_at   DATETIME     NULL         AFTER abilities,
        ADD COLUMN revoked_at   DATETIME     NULL         AFTER expires_at,
        ADD COLUMN paused_at    DATETIME     NULL         AFTER revoked_at,
        ADD COLUMN used_count   INT UNSIGNED NOT NULL DEFAULT 0 AFTER last_used_at,
        ADD COLUMN last_used_ip VARCHAR(45)  NULL         AFTER used_count',
];

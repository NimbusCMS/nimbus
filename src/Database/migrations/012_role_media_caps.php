<?php

declare(strict_types=1);

/**
 * Behavior-preservation backfill (ADR 0011, Slice 3b). Before this slice the
 * media library (`/admin/media`) was open to any signed-in user; Slice 3b gates
 * it on `media:read`/`media:write`. The content wildcard `*:read` does not reach
 * a management capability, so the seeded `editor`/`author` roles — which had only
 * `*:read` — would lose media entirely. This unions `media:read` + `media:write`
 * into those two **system** roles so existing installs keep working; fresh
 * installs get the same pair from RoleSeeder::CONTENT_MEDIA_CAPS.
 *
 * The repo's first data migration. It is safe by construction:
 * - static SQL, no interpolation (no injection surface);
 * - `capabilities` is `JSON NOT NULL` and always a JSON array, so
 *   `JSON_ARRAY_APPEND` is well-typed;
 * - the `JSON_CONTAINS` guard makes each append idempotent;
 * - scoped to `is_system = 1 AND name IN ('editor','author')` — never a custom
 *   role, never `admin` (which already has the super-grant);
 * - migrations run once (tracked in `nb_migrations`), so this can never re-add a
 *   capability an admin later strips. It is additive only — it removes nothing.
 *
 * A role an admin renamed away from `editor`/`author` is intentionally skipped
 * (fails closed: those users lose media until an admin re-grants it — a visible,
 * fixable outcome, never an over-grant).
 */
return [
    "UPDATE nb_roles
        SET capabilities = JSON_ARRAY_APPEND(capabilities, '$', 'media:read')
      WHERE is_system = 1
        AND name IN ('editor', 'author')
        AND NOT JSON_CONTAINS(capabilities, JSON_QUOTE('media:read'))",

    "UPDATE nb_roles
        SET capabilities = JSON_ARRAY_APPEND(capabilities, '$', 'media:write')
      WHERE is_system = 1
        AND name IN ('editor', 'author')
        AND NOT JSON_CONTAINS(capabilities, JSON_QUOTE('media:write'))",
];

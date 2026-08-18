<?php

declare(strict_types=1);

/**
 * A monotonic version per collection, bumped whenever its shape changes
 * (CollectionRepository::update, which every CollectionService::update runs).
 *
 * It mirrors the entry version (migration 007): the schema-management tools
 * (ADR 0009, Slice 4) surface it so a read-before-write concurrency guard can be
 * added later without another migration. The guard itself is deferred; for now
 * the column just tracks and is exposed.
 *
 * Existing rows start at 1; new rows default to 1.
 */

return [
    'ALTER TABLE nb_collections ADD COLUMN version INT UNSIGNED NOT NULL DEFAULT 1 AFTER description',
];

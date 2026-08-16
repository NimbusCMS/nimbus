<?php

declare(strict_types=1);

/**
 * Fixed-window request counters for API rate limiting. One row per key
 * (`ip:<addr>` for the flood guard, `tok:<id>` for the per-token quota): the
 * current window's start (a unix bucket) and the hit count within it. See
 * Nimbus\Http\ApiRateLimiter.
 */

return [
    'CREATE TABLE nb_api_rate (
        id           VARCHAR(190)    NOT NULL PRIMARY KEY,
        window_start BIGINT UNSIGNED NOT NULL,
        hits         INT UNSIGNED    NOT NULL,
        updated_at   DATETIME        NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
];

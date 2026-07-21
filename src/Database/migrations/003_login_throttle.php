<?php

declare(strict_types=1);

/** Tracks failed login attempts per key (IP) for progressive lockout. */
return [
    "CREATE TABLE nb_login_throttle (
        id           VARCHAR(190) NOT NULL PRIMARY KEY,
        attempts     INT UNSIGNED NOT NULL DEFAULT 0,
        last_attempt DATETIME NOT NULL,
        locked_until DATETIME NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
];

<?php

declare(strict_types=1);

namespace Nimbus\Settings;

use Nimbus\Database\Connection;

/**
 * Persistence for the `nb_settings` key/value table. Every query is
 * parameter-bound; the `key` is always supplied by the caller from the typed
 * {@see SettingsRegistry}, never built from a request, so there is no SQL-string
 * assembly and no arbitrary-key surface at this layer either.
 *
 * `set()` is an upsert via the MySQL 8 row-alias form (as used by
 * {@see \Nimbus\Auth\LoginThrottle} and {@see \Nimbus\Http\ApiRateLimiter}),
 * which avoids reusing a named placeholder — a native-prepare hazard.
 */
final class SettingsRepository
{
    public function __construct(private Connection $db)
    {
    }

    /** @return array<string,string> every stored row, key => value */
    public function all(): array
    {
        $out = [];
        foreach ($this->db->select('SELECT `key`, `value` FROM nb_settings') as $row) {
            $out[(string) $row['key']] = (string) ($row['value'] ?? '');
        }
        return $out;
    }

    public function set(string $key, string $value): void
    {
        $this->db->execute(
            'INSERT INTO nb_settings (`key`, `value`) VALUES (:k, :v) AS new
             ON DUPLICATE KEY UPDATE `value` = new.`value`',
            ['k' => $key, 'v' => $value],
        );
    }
}

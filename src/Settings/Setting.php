<?php

declare(strict_types=1);

namespace Nimbus\Settings;

use Closure;

/**
 * One known, admin-editable site setting — a single row in the typed registry
 * ({@see SettingsRegistry}). The registry is an **allow-list**: only keys that
 * exist as a `Setting` can be read with a default or written at all, so a
 * request can never introduce an arbitrary `nb_settings` row (no over-posting).
 *
 * `type` drives how the admin form renders the field (`textarea`, `collection`
 * select); `default` is the fallback when no row is stored (sourced from
 * `config/site.php` so the file stays the shipped default and the DB is an
 * override); `validate` returns an error string, or null when the value is
 * acceptable — the single place a value is checked before it is stored.
 */
final class Setting
{
    /** @param Closure(string):?string $validate */
    public function __construct(
        public readonly string $key,
        public readonly string $type,
        public readonly string $label,
        public readonly string $help,
        public readonly string $default,
        public readonly Closure $validate,
    ) {
    }

    /** Validate a submitted value; null means it is acceptable to store. */
    public function validate(string $value): ?string
    {
        return ($this->validate)($value);
    }
}

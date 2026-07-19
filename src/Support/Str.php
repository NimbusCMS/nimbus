<?php

declare(strict_types=1);

namespace Nimbus\Support;

final class Str
{
    /** URL/handle-safe slug: lowercase, words joined by a separator. */
    public static function slug(string $value, string $sep = '-'): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/', $sep, $value) ?? '';
        return trim($value, $sep);
    }

    /** Handle: like a slug but with underscores (safe as an array/JSON key). */
    public static function handle(string $value): string
    {
        return self::slug($value, '_');
    }

    public static function truncate(string $value, int $length = 60): string
    {
        return mb_strlen($value) > $length ? mb_substr($value, 0, $length) . '…' : $value;
    }
}

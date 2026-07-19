<?php

declare(strict_types=1);

namespace Nimbus\Http;

/** Per-session CSRF token for state-changing requests. */
final class Csrf
{
    private const KEY = '_nimbus_csrf';

    public static function token(): string
    {
        if (empty($_SESSION[self::KEY])) {
            $_SESSION[self::KEY] = bin2hex(random_bytes(32));
        }
        return $_SESSION[self::KEY];
    }

    public static function check(?string $token): bool
    {
        return is_string($token)
            && !empty($_SESSION[self::KEY])
            && hash_equals($_SESSION[self::KEY], $token);
    }
}

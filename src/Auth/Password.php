<?php

declare(strict_types=1);

namespace Nimbus\Auth;

/** Modern password hashing: argon2id when the runtime supports it, else bcrypt. */
final class Password
{
    private static function algo(): string
    {
        return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
    }

    public static function hash(string $plain): string
    {
        return password_hash($plain, self::algo());
    }

    public static function verify(string $plain, string $hash): bool
    {
        return $hash !== '' && password_verify($plain, $hash);
    }

    public static function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, self::algo());
    }

    /**
     * The same floor the installer enforces: at least 8 characters and not an
     * obvious default. Shared so user-creation paths (CLI, MCP) agree.
     */
    public static function isWeak(string $plain): bool
    {
        return strlen($plain) < 8
            || in_array(strtolower($plain), ['password', 'admin', '123456', 'changeme'], true);
    }
}

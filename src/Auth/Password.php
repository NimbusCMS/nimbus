<?php

declare(strict_types=1);

namespace Nimbus\Auth;

/** Modern password hashing: argon2id when the runtime supports it, else bcrypt. */
final class Password
{
    private static function algo(): string|int
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
}

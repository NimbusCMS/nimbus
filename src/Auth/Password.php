<?php

declare(strict_types=1);

namespace Nimbus\Auth;

/** Modern password hashing: argon2id when the runtime supports it, else bcrypt. */
final class Password
{
    /** The minimum length for a newly-set password, enforced everywhere via isWeak(). */
    public const MIN_LENGTH = 12;

    /**
     * Fixed dummy hashes for equal-work login (AUTH-1): {@see dummyHash()} returns
     * the one matching the runtime algo, so a login for an unknown email runs the
     * *same* verify cost as a known one — no timing/enumeration oracle. Generated
     * at the current default params; the guard `dummyHash()` params are pinned by
     * a test (algoName match + no rehash) so a param bump or a bcrypt-only host is
     * caught rather than silently un-equalizing the work.
     */
    private const DUMMY_ARGON2ID = '$argon2id$v=19$m=65536,t=4,p=1$bGFLNG5uREg3Ny9LQXRtdQ$9xiqT6ze3YLE7Y74v92AB1ezuXsorVLMtwtrM6AT2Ng';
    private const DUMMY_BCRYPT   = '$2y$10$pJt.lxxooPi91Rf7cyESOe9i2q5/BiaXgknuenvoS45sBoHAUZQPa';

    private static function algo(): string
    {
        return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
    }

    /** A valid hash in the runtime's algorithm, for equal-work verification against an unknown email. */
    public static function dummyHash(): string
    {
        return defined('PASSWORD_ARGON2ID') && self::algo() === PASSWORD_ARGON2ID
            ? self::DUMMY_ARGON2ID
            : self::DUMMY_BCRYPT;
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
     * The one password floor, shared by every set path (installer, admin,
     * reset/accept, MCP) so they can't drift: at least {@see MIN_LENGTH}
     * characters and not an obvious default. Runs only when a password is *set* —
     * never at login — so raising the floor locks out no existing user.
     */
    public static function isWeak(string $plain): bool
    {
        return strlen($plain) < self::MIN_LENGTH
            || in_array(strtolower($plain), ['password', 'admin', '123456', 'changeme'], true);
    }
}

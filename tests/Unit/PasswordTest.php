<?php

declare(strict_types=1);

namespace Nimbus\Tests\Unit;

use Nimbus\Auth\Password;
use PHPUnit\Framework\TestCase;

/**
 * The password policy + the equal-work dummy hash (AUTH-1/AUTH-3).
 */
final class PasswordTest extends TestCase
{
    // ---------------------------------------------- AUTH-1: the dummy hash drift guard

    public function test_the_dummy_hash_matches_the_runtime_algo_and_needs_no_rehash(): void
    {
        // The load-bearing invariant: the dummy must be the SAME algorithm and
        // params as a real password, or the unknown-email login path does
        // different work and re-opens the timing oracle. This fails loudly if PHP's
        // default params bump OR the runtime is bcrypt-only (algo mismatch) — the
        // signal to regenerate the constant.
        $runtimeAlgo = password_get_info(Password::hash('probe-password'))['algoName'];
        $dummyAlgo   = password_get_info(Password::dummyHash())['algoName'];

        self::assertSame($runtimeAlgo, $dummyAlgo, 'the dummy hash must use the runtime algorithm');
        self::assertFalse(Password::needsRehash(Password::dummyHash()), 'the dummy hash must be at current params');
    }

    public function test_a_real_password_never_verifies_against_the_dummy(): void
    {
        // Belt-and-braces: the dummy is a verification target, not a credential.
        self::assertFalse(Password::verify('nimbus-timing-equalizer', Password::hash('something-else')));
        // And verifying anything against the dummy simply fails (it does the work, returns false).
        self::assertFalse(Password::verify('any-password-at-all', Password::dummyHash()));
    }

    // ---------------------------------------------- AUTH-3: one floor, MIN_LENGTH

    public function test_the_weak_floor_is_min_length(): void
    {
        self::assertSame(12, Password::MIN_LENGTH, 'the floor is a single source of truth');
        self::assertTrue(Password::isWeak(str_repeat('a', Password::MIN_LENGTH - 1)), 'one under the floor is weak');
        self::assertFalse(Password::isWeak(str_repeat('a', Password::MIN_LENGTH)), 'at the floor is acceptable');
    }

    public function test_the_denylist_still_rejects_obvious_defaults(): void
    {
        foreach (['password', 'admin', '123456', 'changeme'] as $default) {
            self::assertTrue(Password::isWeak($default), "\"{$default}\" is always weak");
        }
    }
}

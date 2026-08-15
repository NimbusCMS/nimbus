<?php

declare(strict_types=1);

namespace Nimbus\Http;

/**
 * Single-use form nonces.
 *
 * Defeats the double-submit / reload-resubmit of a POST that *renders* its
 * result instead of redirecting — the token mint, whose plaintext can survive
 * only in the response body, never a redirect. `issue()` mints a nonce and
 * records it in the session; `consume()` accepts it exactly once.
 *
 * Distinct from {@see Csrf}, which is a per-session token that is deliberately
 * *not* single-use. A small window of outstanding nonces is kept so two open
 * forms (or tabs) both work.
 */
final class FormNonce
{
    private const KEY = '_nimbus_form_nonces';
    private const MAX = 8;

    public static function issue(): string
    {
        $nonce  = bin2hex(random_bytes(16));
        $live   = self::live();
        $live[] = $nonce;
        // Bound growth: keep only the most recent MAX outstanding nonces.
        $_SESSION[self::KEY] = array_slice($live, -self::MAX);

        return $nonce;
    }

    /** Accept a nonce exactly once; false if unknown, empty, or already used. */
    public static function consume(?string $nonce): bool
    {
        if (!is_string($nonce) || $nonce === '') {
            return false;
        }
        $live  = self::live();
        $index = array_search($nonce, $live, true);
        if ($index === false) {
            return false;
        }
        unset($live[$index]);
        $_SESSION[self::KEY] = array_values($live);

        return true;
    }

    /** @return list<string> */
    private static function live(): array
    {
        $live = $_SESSION[self::KEY] ?? [];

        return is_array($live) ? array_values(array_filter($live, 'is_string')) : [];
    }
}

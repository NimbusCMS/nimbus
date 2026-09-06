<?php

declare(strict_types=1);

namespace Nimbus\Tests\Unit;

use Nimbus\Support\Config;
use PHPUnit\Framework\TestCase;

/**
 * Config validation that must not trust the file on disk — a typo in a config
 * file should be dropped, never break routing or reach a template.
 */
final class ConfigTest extends TestCase
{
    public function test_a_string_redirect_target_is_a_permanent_301(): void
    {
        self::assertSame(
            ['/old' => ['to' => '/posts/new', 'status' => 301]],
            Config::normalizeRedirects(['/old' => '/posts/new']),
        );
    }

    public function test_the_array_form_chooses_the_status(): void
    {
        self::assertSame(
            ['/promo' => ['to' => '/posts/sale', 'status' => 302]],
            Config::normalizeRedirects(['/promo' => ['to' => '/posts/sale', 'status' => 302]]),
        );
    }

    public function test_malformed_redirect_entries_are_dropped(): void
    {
        $out = Config::normalizeRedirects([
            ''    => '/x',                                  // empty source
            '/a'  => ['status' => 301],                     // no destination
            '/b'  => ['to' => '/c', 'status' => 999],       // status not a redirect
            '/d'  => 123,                                    // not a string or array
            '/ok' => '/fine',                                // the one good entry
        ]);

        self::assertSame(['/ok' => ['to' => '/fine', 'status' => 301]], $out);
    }

    public function test_a_non_array_config_is_empty(): void
    {
        self::assertSame([], Config::normalizeRedirects('nonsense'));
    }

    // ---------------------------------------------------------- demo accounts

    public function test_demo_accounts_parse_from_the_config_shape(): void
    {
        $out = Config::normalizeDemoAccounts([
            'accounts' => [
                ['label' => 'Manager', 'email' => 'm@x.demo', 'password' => 'pw1'],
                ['label' => 'Cook', 'email' => 'c@x.demo', 'password' => 'pw2'],
            ],
        ], '', '');

        self::assertSame([
            ['label' => 'Manager', 'email' => 'm@x.demo', 'password' => 'pw1'],
            ['label' => 'Cook', 'email' => 'c@x.demo', 'password' => 'pw2'],
        ], $out);
    }

    public function test_malformed_demo_account_rows_are_dropped(): void
    {
        $out = Config::normalizeDemoAccounts([
            'accounts' => [
                ['label' => '', 'email' => 'a@x.demo', 'password' => 'pw'],   // no label
                ['label' => 'B', 'email' => '', 'password' => 'pw'],          // no email
                ['label' => 'C', 'email' => 'c@x.demo', 'password' => ''],    // no password
                'not-an-array',                                              // wrong type
                ['label' => 'OK', 'email' => 'ok@x.demo', 'password' => 'pw'],
            ],
        ], '', '');

        self::assertSame([['label' => 'OK', 'email' => 'ok@x.demo', 'password' => 'pw']], $out);
    }

    public function test_demo_accounts_fall_back_to_the_single_env_pair(): void
    {
        // No file config → the one published credential becomes a single account.
        self::assertSame(
            [['label' => 'Demo', 'email' => 'solo@x.demo', 'password' => 'pw']],
            Config::normalizeDemoAccounts(null, 'solo@x.demo', 'pw'),
        );
    }

    public function test_demo_accounts_are_empty_with_neither_file_nor_env(): void
    {
        self::assertSame([], Config::normalizeDemoAccounts(null, '', ''));
        self::assertSame([], Config::normalizeDemoAccounts('nonsense', '', ''));
    }

    public function test_file_accounts_win_over_the_env_fallback(): void
    {
        $out = Config::normalizeDemoAccounts(
            ['accounts' => [['label' => 'A', 'email' => 'a@x.demo', 'password' => 'pw']]],
            'env@x.demo',
            'envpw',
        );
        self::assertSame([['label' => 'A', 'email' => 'a@x.demo', 'password' => 'pw']], $out, 'the env pair is not appended when the file lists accounts');
    }
}

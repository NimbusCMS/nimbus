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
}

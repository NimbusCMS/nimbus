<?php

declare(strict_types=1);

namespace Nimbus\Tests\Unit;

use Nimbus\Support\PageCache;
use PHPUnit\Framework\TestCase;

final class PageCacheTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/nimbus-pc-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
    }

    public function test_a_disabled_cache_stores_nothing(): void
    {
        $cache = new PageCache($this->dir, 0);
        $cache->put('/x', 'hi');

        self::assertFalse($cache->enabled());
        self::assertNull($cache->get('/x'));
    }

    public function test_put_then_get_returns_the_page(): void
    {
        $cache = new PageCache($this->dir, 300);
        $cache->put('/x', '<p>hi</p>');

        self::assertSame('<p>hi</p>', $cache->get('/x'));
    }

    public function test_an_entry_expires_at_its_ttl(): void
    {
        $now   = 1000;
        $cache = new PageCache($this->dir, 60, function () use (&$now): int {
            return $now;
        });
        $cache->put('/x', 'hi');
        self::assertSame('hi', $cache->get('/x'));

        $now = 1060; // exactly ttl seconds later
        self::assertNull($cache->get('/x'), 'a page is stale once its ttl has elapsed');
    }

    public function test_flush_drops_every_page(): void
    {
        $cache = new PageCache($this->dir, 300);
        $cache->put('/a', 'A');
        $cache->put('/b', 'B');

        $cache->flush();

        self::assertNull($cache->get('/a'));
        self::assertNull($cache->get('/b'));
    }

    public function test_html_with_newlines_survives_a_round_trip(): void
    {
        $cache = new PageCache($this->dir, 300);
        $html  = "one\ntwo\n<p>ok</p>";
        $cache->put('/x', $html);

        self::assertSame($html, $cache->get('/x'), 'only the first newline delimits the timestamp');
    }
}

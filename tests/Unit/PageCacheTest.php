<?php

declare(strict_types=1);

namespace Nimbus\Tests\Unit;

use Nimbus\Support\PageCache;
use PHPUnit\Framework\TestCase;

final class PageCacheTest extends TestCase
{
    private string $dir;

    /** A value in the exact shape Csp emits (base64 of 16 bytes). */
    private string $nonce;

    protected function setUp(): void
    {
        $this->dir   = sys_get_temp_dir() . '/nimbus-pc-' . bin2hex(random_bytes(4));
        $this->nonce = base64_encode(random_bytes(16));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
    }

    /** The hashed file path a key resolves to, so a test can plant a raw entry. */
    private function fileFor(string $key): string
    {
        return $this->dir . '/' . hash('sha256', $key) . '.cache';
    }

    public function test_a_disabled_cache_stores_nothing(): void
    {
        $cache = new PageCache($this->dir, 0);
        $cache->put('/x', 'hi', $this->nonce);

        self::assertFalse($cache->enabled());
        self::assertNull($cache->get('/x'));
    }

    public function test_put_then_get_returns_the_page_and_its_nonce(): void
    {
        $cache = new PageCache($this->dir, 300);
        $cache->put('/x', '<p>hi</p>', $this->nonce);

        self::assertSame(['html' => '<p>hi</p>', 'nonce' => $this->nonce], $cache->get('/x'));
    }

    public function test_an_entry_expires_at_its_ttl(): void
    {
        $now   = 1000;
        $cache = new PageCache($this->dir, 60, function () use (&$now): int {
            return $now;
        });
        $cache->put('/x', 'hi', $this->nonce);
        self::assertNotNull($cache->get('/x'));

        $now = 1060; // exactly ttl seconds later
        self::assertNull($cache->get('/x'), 'a page is stale once its ttl has elapsed');
    }

    public function test_flush_drops_every_page(): void
    {
        $cache = new PageCache($this->dir, 300);
        $cache->put('/a', 'A', $this->nonce);
        $cache->put('/b', 'B', $this->nonce);

        $cache->flush();

        self::assertNull($cache->get('/a'));
        self::assertNull($cache->get('/b'));
    }

    public function test_html_with_newlines_survives_a_round_trip(): void
    {
        $cache = new PageCache($this->dir, 300);
        $html  = "one\ntwo\n<p>ok</p>";
        $cache->put('/x', $html, $this->nonce);

        self::assertSame($html, $cache->get('/x')['html'] ?? null, 'only the first two newlines delimit the header');
    }

    // ---------------------------------------------------- format guard (HTTP-1 / A1)

    public function test_a_legacy_pre_nonce_entry_is_treated_as_a_miss(): void
    {
        // The format before Slice H: timestamp\nHTML, where the HTML has its own
        // newlines. The new parse must NOT read the HTML's first line as a nonce.
        @mkdir($this->dir, 0o775, true);
        file_put_contents($this->fileFor('/x'), time() . "\n<!doctype html>\n<body>hi</body>");

        $cache = new PageCache($this->dir, 300);
        self::assertNull($cache->get('/x'), 'a legacy entry is not trusted');
        self::assertFileDoesNotExist($this->fileFor('/x'), 'and it is unlinked so the next request re-renders');
    }

    public function test_an_entry_with_a_non_base64_nonce_is_a_miss(): void
    {
        @mkdir($this->dir, 0o775, true);
        // A crafted entry whose "nonce" line is HTML — must never reach Csp::adopt().
        file_put_contents($this->fileFor('/x'), time() . "\n<!doctype html>\nbody");

        $cache = new PageCache($this->dir, 300);
        self::assertNull($cache->get('/x'));
    }

    public function test_put_refuses_a_nonce_that_is_not_the_emitted_shape(): void
    {
        $cache = new PageCache($this->dir, 300);
        $cache->put('/x', 'hi', "not\na\nnonce");

        self::assertNull($cache->get('/x'), 'a malformed nonce is never persisted');
    }
}

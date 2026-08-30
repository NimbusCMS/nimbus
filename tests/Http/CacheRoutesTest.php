<?php

declare(strict_types=1);

namespace Nimbus\Tests\Http;

use Nimbus\Application;
use Nimbus\Content\Collection;
use Nimbus\Http\Csrf;
use Nimbus\Support\PageCache;

/**
 * Page caching through the real kernel: a hit skips rendering, a content write
 * flushes, and admin/API/asset paths are never cached. Each test drives one
 * Application instance so a single filesystem cache is shared across its
 * requests.
 */
final class CacheRoutesTest extends HttpTestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/nimbus-cache-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    private function appUsing(PageCache $cache): Application
    {
        return new Application($this->db, $this->auth, [], $cache);
    }

    /** Insert a live entry directly, bypassing events (pure seeding). */
    private function seedLive(Collection $c, string $title, string $slug): void
    {
        $this->db->insert(
            "INSERT INTO nb_entries (collection_id, title, slug, status, data, published_at, created_at, updated_at)
             VALUES (:c, :t, :s, 'published', '{}', NOW(), NOW(), NOW())",
            ['c' => $c->id, 't' => $title, 's' => $slug],
        );
    }

    public function test_a_public_page_is_served_from_cache(): void
    {
        $c = $this->makeCollection('posts');
        $this->seedLive($c, 'Cached Hello', 'hello');

        $app = $this->appUsing(new PageCache($this->dir, 300));
        self::assertStringContainsString('Cached Hello', $app->handle($this->request('GET', '/posts'))->body);

        // Remove the entry with no event — a cached page must still serve it.
        $this->db->execute('DELETE FROM nb_entries WHERE slug = :s', ['s' => 'hello']);

        self::assertStringContainsString(
            'Cached Hello',
            $app->handle($this->request('GET', '/posts'))->body,
            'the second request is served from cache, not re-queried',
        );
    }

    public function test_distinct_query_strings_do_not_mint_distinct_cache_files(): void
    {
        // HTTP-6: the cache key is path + ?page only, so distinct query strings
        // on a path share ONE entry — the documented no-query-vary contract, and
        // the guard that keeps SVM-1's disk-fill bound closed. If someone later
        // "fixes" HTTP-6 by folding the query into the key, this fails: an
        // anonymous client could then mint unbounded cache files with ?x=1,2,3…
        $c = $this->makeCollection('posts');
        $this->seedLive($c, 'Hello', 'hello');

        $cache = new PageCache($this->dir, 300);
        $app   = $this->appUsing($cache);

        foreach (['a', 'b', 'c', 'd', 'e'] as $q) {
            self::assertSame(200, $app->handle($this->request('GET', '/posts', ['tag' => $q]))->status);
        }

        self::assertCount(1, glob($this->dir . '/*') ?: [], 'distinct query strings must not each mint a cache file (SVM-1 bound)');
        self::assertNotNull($cache->get('/posts'), 'the one entry is keyed on the bare path');
    }

    public function test_the_public_front_end_reads_no_query_input_but_page(): void
    {
        // HTTP-6 drift guard: keying the cache on path + ?page only is safe just
        // while the front end's output varies only on those. A future
        // query-varying public handler (?tag/?q/?sort) would silently serve stale
        // content under the cache — it must fail loudly HERE first and honor the
        // no-query-vary contract (or ship PAGE_CACHE_TTL=0 guidance).
        //
        // `preview` is the one allowed exception (ADR 0021): it varies output, but a
        // request carrying it is never cached — Application::cacheKey() returns null
        // when it is present — so it can never serve stale content. Any OTHER new key
        // still trips this guard.
        $src = (string) file_get_contents(\dirname(__DIR__, 2) . '/src/Site/SiteController.php');
        preg_match_all("/query\\(\\s*'([^']*)'/", $src, $m);
        $keys = array_values(array_unique($m[1]));
        sort($keys);
        self::assertSame(['page', 'preview'], $keys, 'SiteController may read only "page" (cache vary) and "preview" (uncacheable, ADR 0021)');
    }

    public function test_a_non_browsable_collection_is_not_cached(): void
    {
        // SVM-4 + SVM-1: /blocks now 404s (a fragment store is not a page), and
        // 404s are never cached — the fix shrinks the cacheable public surface.
        $blocks = $this->makeCollection('blocks');
        $this->seedLive($blocks, 'Announcement', 'announcement');

        $cache = new PageCache($this->dir, 300);
        $app   = $this->appUsing($cache);

        self::assertSame(404, $app->handle($this->request('GET', '/blocks'))->status);
        self::assertNull($cache->get('/blocks'), 'a non-browsable 404 is never cached');
        self::assertCount(0, glob($this->dir . '/*') ?: [], 'no cache file minted for /blocks');
    }

    public function test_caching_disabled_reflects_changes_immediately(): void
    {
        $c = $this->makeCollection('posts');
        $this->seedLive($c, 'Fresh Hello', 'hello');

        $app = $this->appUsing(new PageCache($this->dir, 0)); // disabled
        $app->handle($this->request('GET', '/posts'));
        $this->db->execute('DELETE FROM nb_entries WHERE slug = :s', ['s' => 'hello']);

        self::assertStringNotContainsString('Fresh Hello', $app->handle($this->request('GET', '/posts'))->body);
    }

    public function test_a_content_write_flushes_the_cache(): void
    {
        $this->actingAs('admin');
        $c = $this->makeCollection('posts');
        $this->seedLive($c, 'Hello', 'hello');

        $cache = new PageCache($this->dir, 300);
        $app   = $this->appUsing($cache);
        $app->handle($this->request('GET', '/posts'));
        self::assertNotNull($cache->get('/posts'), 'the page is cached after the first request');

        // A write through the app's own admin endpoint dispatches ENTRY_SAVED,
        // whose listener flushes the cache.
        $app->handle($this->request('POST', '/admin/collections/posts/entries', [], [
            'title' => 'A New Post', 'status' => 'draft', '_token' => Csrf::token(),
        ]));

        self::assertNull($cache->get('/posts'), 'the write flushed the cache');
    }

    public function test_an_out_of_range_page_mints_no_cache_file(): void
    {
        // SVM-1: the disk-fill vector is cacheKey minting one file per ?page=N.
        // A collection page past the end is a 404 (uncached); and the cacheKey
        // ceiling keeps an absurd ?page from minting a file on the home/entry
        // routes, which 200 but ignore the param.
        $c = $this->makeCollection('posts');
        $this->seedLive($c, 'Hello', 'hello');

        $cache = new PageCache($this->dir, 300);
        $app   = $this->appUsing($cache);

        self::assertSame(404, $app->handle($this->request('GET', '/posts', ['page' => '2']))->status);
        self::assertNull($cache->get('/posts?page=2'), 'a 404 out-of-range page is never stored');

        // The entry route 200s but ignores ?page; above the ceiling it is uncached.
        self::assertSame(200, $app->handle($this->request('GET', '/posts/hello', ['page' => '99999']))->status);
        self::assertNull($cache->get('/posts/hello?page=99999'), 'the cacheKey ceiling stops the mint on non-collection routes');
    }

    public function test_admin_pages_are_never_cached(): void
    {
        $this->actingAs('admin');
        $cache = new PageCache($this->dir, 300);
        $app   = $this->appUsing($cache);

        $app->handle($this->request('GET', '/admin'));

        self::assertNull($cache->get('/admin'), 'admin is per-user; never cache it');
    }

    // ------------------------------------------------- nonce × cache (HTTP-1)

    /** The nonce baked into a directive of the CSP header, or '' if absent. */
    private function headerNonce(\Nimbus\Http\Response $r, string $directive): string
    {
        preg_match("/{$directive} 'self' 'nonce-([^']+)'/", (string) $r->header('Content-Security-Policy'), $m);
        return $m[1] ?? '';
    }

    public function test_a_cache_hit_reemits_the_stored_nonce(): void
    {
        $c = $this->makeCollection('posts');
        $this->seedLive($c, 'Cached Hello', 'hello');

        $cache = new PageCache($this->dir, 300);
        $app   = $this->appUsing($cache);

        // Miss: rendered fresh, then stored WITH the nonce it was rendered under.
        // The response header carries that same nonce (as it always did within a
        // single request).
        $miss   = $app->handle($this->request('GET', '/posts'));
        $stored = $cache->get('/posts')['nonce'] ?? '';
        self::assertTrue(\Nimbus\Http\Csp::isValid($stored), 'the page is stored with its nonce');
        self::assertSame($stored, $this->headerNonce($miss, 'script-src'));
        self::assertSame($stored, $this->headerNonce($miss, 'style-src'));

        // Hit: served from the file. handle() rotates a fresh nonce, but the
        // cache path must ADOPT the stored one, so the header re-emits exactly the
        // nonce the cached body was rendered under — the HTTP-1 regression. Before
        // Slice H the header carried the freshly-rotated (mismatched) nonce and
        // every inline script/style on the cached page was blocked.
        $hit = $app->handle($this->request('GET', '/posts'));
        self::assertSame($miss->body, $hit->body, 'a hit is a byte-identical replay');
        self::assertSame($stored, $this->headerNonce($hit, 'script-src'), 'script-src re-emits the stored nonce');
        self::assertSame($stored, $this->headerNonce($hit, 'style-src'), 'style-src re-emits it too');
    }

    public function test_a_head_request_neither_populates_nor_is_served_from_the_cache(): void
    {
        // HTTP-2 × cache: cacheKey is GET-only, and the body strip happens after
        // the store — so a HEAD never writes an (empty) entry, and a later GET
        // renders the full body. Guards against a future refactor poisoning it.
        $c = $this->makeCollection('posts');
        $this->seedLive($c, 'Cached Hello', 'hello');

        $cache = new PageCache($this->dir, 300);
        $app   = $this->appUsing($cache);

        $head = $app->handle($this->request('HEAD', '/posts'));
        self::assertSame(200, $head->status);
        self::assertSame('', $head->body, 'HEAD carries no body');
        self::assertNull($cache->get('/posts'), 'HEAD did not populate the cache');

        self::assertStringContainsString(
            'Cached Hello',
            $app->handle($this->request('GET', '/posts'))->body,
            'the following GET renders the full body, not an empty HEAD reply',
        );
    }

    public function test_a_content_write_rotates_the_cached_nonce(): void
    {
        // The invariant the stable-nonce safety argument rests on: a write flushes
        // the cache, so the next render mints a FRESH nonce. A payload stored
        // knowing the old nonce therefore never meets it in a live header.
        $this->actingAs('admin');
        $c = $this->makeCollection('posts');
        $this->seedLive($c, 'Hello', 'hello');

        $app = $this->appUsing(new PageCache($this->dir, 300));
        $first = $app->handle($this->request('GET', '/posts'));
        $before = $this->headerNonce($first, 'script-src');
        self::assertNotSame('', $before);

        $app->handle($this->request('POST', '/admin/collections/posts/entries', [], [
            'title' => 'Another', 'status' => 'draft', '_token' => Csrf::token(),
        ]));

        $after = $this->headerNonce($app->handle($this->request('GET', '/posts')), 'script-src');
        self::assertNotSame($before, $after, 'the write flushed the cache and the re-render rotated the nonce');
    }
}

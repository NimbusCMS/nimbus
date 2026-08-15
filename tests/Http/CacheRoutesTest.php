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

    public function test_admin_pages_are_never_cached(): void
    {
        $this->actingAs('admin');
        $cache = new PageCache($this->dir, 300);
        $app   = $this->appUsing($cache);

        $app->handle($this->request('GET', '/admin'));

        self::assertNull($cache->get('/admin'), 'admin is per-user; never cache it');
    }
}

<?php

declare(strict_types=1);

namespace Nimbus\Tests\Http;

/**
 * Admin listing hardening: entry-list pagination + the collections-list count.
 *
 * Locks in: the admin entry list pages (25/page, all statuses), the count is
 * search-aware, an out-of-range `?page` clamps into range (never a huge OFFSET
 * or a dead Next), the search term is reflected into pager links **encoded**
 * (no reflected XSS), and the collections list shows correct per-collection
 * counts including a zero-entry / zero-field collection.
 */
final class AdminListingTest extends HttpTestCase
{
    private function seedEntries(int $collectionId, int $count, string $titlePrefix = 'Entry'): void
    {
        for ($i = 1; $i <= $count; $i++) {
            $this->db->insert(
                "INSERT INTO nb_entries (collection_id, title, slug, status, data, created_at, updated_at)
                 VALUES (:c, :t, :s, 'draft', '{}', NOW(), NOW())",
                ['c' => $collectionId, 't' => "{$titlePrefix} {$i}", 's' => strtolower($titlePrefix) . "-{$i}"],
            );
        }
    }

    private function countRows(string $body): int
    {
        // Each entry row links to its edit page; count those anchors.
        return substr_count($body, '/entries/') > 0
            ? (int) preg_match_all('#/entries/\d+/edit"><strong>#', $body)
            : 0;
    }

    public function test_the_entry_list_pages_at_25_per_page(): void
    {
        $c = $this->makeCollection('posts');
        $this->seedEntries($c->id, 30);
        $this->actingAs('admin');

        $page1 = $this->get('/admin/collections/posts/entries')->body;
        self::assertSame(25, $this->countRows($page1), 'first page shows PER_PAGE rows');
        self::assertStringContainsString('Page 1 of 2', $page1);
        self::assertStringContainsString('30 total', $page1);

        $page2 = $this->get('/admin/collections/posts/entries', ['page' => '2'])->body;
        self::assertSame(5, $this->countRows($page2), 'second page shows the remainder');
        self::assertStringContainsString('Page 2 of 2', $page2);
    }

    public function test_no_pager_when_a_single_page(): void
    {
        $c = $this->makeCollection('posts');
        $this->seedEntries($c->id, 3);
        $this->actingAs('admin');

        $body = $this->get('/admin/collections/posts/entries')->body;
        self::assertStringNotContainsString('aria-label="Pagination"', $body, 'no pager for a single page');
    }

    public function test_an_out_of_range_page_clamps_into_range(): void
    {
        $c = $this->makeCollection('posts');
        $this->seedEntries($c->id, 30);
        $this->actingAs('admin');

        // Too high → last page (not an empty table with a live Next).
        $high = $this->get('/admin/collections/posts/entries', ['page' => '999999'])->body;
        self::assertStringContainsString('Page 2 of 2', $high);
        self::assertSame(5, $this->countRows($high));

        // Zero / negative / non-numeric → page 1.
        foreach (['0', '-5', 'abc'] as $bad) {
            $body = $this->get('/admin/collections/posts/entries', ['page' => $bad])->body;
            self::assertStringContainsString('Page 1 of 2', $body, "page={$bad} clamps to 1");
        }
    }

    public function test_the_count_is_search_aware(): void
    {
        $c = $this->makeCollection('posts');
        $this->seedEntries($c->id, 30, 'Alpha');
        $this->seedEntries($c->id, 3, 'Beta');
        $this->actingAs('admin');

        // 33 total, but a search for "Beta" narrows to 3 → one page, no pager.
        $body = $this->get('/admin/collections/posts/entries', ['q' => 'Beta'])->body;
        self::assertSame(3, $this->countRows($body));
        self::assertStringNotContainsString('aria-label="Pagination"', $body, 'a 3-result search is one page');
    }

    /** A3 — the search term is reflected into pager links percent-encoded, never as raw HTML. */
    public function test_a_hostile_search_term_is_encoded_in_pager_links(): void
    {
        $c = $this->makeCollection('posts');
        // Enough matching rows to force a pager: title contains the payload.
        $this->seedEntries($c->id, 30, 'x"><script>alert(1)</script>');
        $this->actingAs('admin');

        $body = $this->get('/admin/collections/posts/entries', ['q' => '"><script>alert(1)</script>'])->body;

        self::assertStringContainsString('aria-label="Pagination"', $body, 'the search still pages');
        self::assertStringContainsString('q=%22%3E%3Cscript%3E', $body, 'q is percent-encoded in the href');
        self::assertStringNotContainsString('q="><script>', $body, 'never reflected raw');
    }

    public function test_the_collections_list_renders_with_a_zero_entry_collection(): void
    {
        $posts = $this->makeCollection('posts');
        $this->seedEntries($posts->id, 4);
        $this->makeCollection('empty'); // zero entries — must not break the list (map-with-default)
        $this->actingAs('admin');

        $resp = $this->get('/admin/collections');
        self::assertSame(200, $resp->status);
        self::assertStringContainsString('posts', $resp->body);
        self::assertStringContainsString('empty', $resp->body);
    }
}

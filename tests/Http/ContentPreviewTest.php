<?php

declare(strict_types=1);

namespace Nimbus\Tests\Http;

use Nimbus\Content\PreviewTokens;
use Nimbus\Http\Request;

/**
 * Content preview (ADR 0021): an entry-scoped, short-lived token renders ONE
 * unpublished entry through the rendered site (`?preview=`) and the headless API,
 * without ever exposing it to the public or leaking that a draft exists.
 */
final class ContentPreviewTest extends HttpTestCase
{
    /** @return array{collection_id:int,id:int,slug:string} */
    private function draft(string $title): array
    {
        $c = $this->makeCollection('posts');
        $this->actingAs('admin');
        $this->post('/admin/collections/posts/entries', ['title' => $title, 'status' => 'draft']);
        $row = $this->db->selectOne('SELECT id, slug FROM nb_entries WHERE collection_id = :c ORDER BY id DESC LIMIT 1', ['c' => $c->id]);
        return ['collection_id' => $c->id, 'id' => (int) $row['id'], 'slug' => (string) $row['slug']];
    }

    private function token(int $collectionId, int $entryId): string
    {
        return (new PreviewTokens($this->db))->issue($collectionId, $entryId, null);
    }

    /** @param array<string,string> $query */
    private function apiGet(string $path, array $query, ?string $bearer = null): \Nimbus\Http\Response
    {
        $server = ['REMOTE_ADDR' => '127.0.0.1'];
        if ($bearer !== null) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer ' . $bearer;
        }
        return $this->throughKernel(new Request('GET', $path, $query, [], $server, []));
    }

    // ------------------------------------------------------------ rendered site

    public function test_a_draft_404s_without_a_token(): void
    {
        $d = $this->draft('Secret Draft');
        self::assertSame(404, $this->get('/posts/' . $d['slug'])->status);
    }

    public function test_a_valid_token_renders_the_draft_with_a_banner_and_no_store(): void
    {
        $d        = $this->draft('Secret Draft');
        $response = $this->get('/posts/' . $d['slug'], ['preview' => $this->token($d['collection_id'], $d['id'])]);

        self::assertSame(200, $response->status, 'a valid preview renders the draft');
        self::assertStringContainsString('Secret Draft', $response->body);
        self::assertStringContainsString('nb-preview-banner', $response->body, 'the unpublished-draft banner');
        self::assertSame('no-store', $response->header('Cache-Control'), 'a preview is never cached');
        self::assertSame('no-referrer', $response->header('Referrer-Policy'), 'the token must not leak via Referer');
        self::assertStringContainsString('noindex', (string) $response->header('X-Robots-Tag'));
    }

    public function test_an_invalid_token_is_indistinguishable_from_a_missing_entry(): void
    {
        $d       = $this->draft('Secret Draft');
        $bad     = $this->get('/posts/' . $d['slug'], ['preview' => 'deadbeef-not-a-real-token']);
        $missing = $this->get('/posts/' . $d['slug']);

        self::assertSame(404, $bad->status);
        self::assertSame($missing->status, $bad->status);
        self::assertSame($missing->body, $bad->body, 'no oracle: a bad token 404s byte-identically to a plain draft URL');
    }

    public function test_a_token_for_one_entry_cannot_render_another(): void
    {
        $c = $this->makeCollection('posts');
        $this->actingAs('admin');
        $this->post('/admin/collections/posts/entries', ['title' => 'Draft Alpha', 'status' => 'draft']);
        $this->post('/admin/collections/posts/entries', ['title' => 'Draft Bravo', 'status' => 'draft']);
        $rows    = $this->db->select('SELECT id, slug FROM nb_entries WHERE collection_id = :c ORDER BY id', ['c' => $c->id]);
        $tokenA  = (new PreviewTokens($this->db))->issue($c->id, (int) $rows[0]['id'], null);

        // Alpha's token on Bravo's URL → falls through → Bravo is a draft → 404.
        self::assertSame(404, $this->get('/posts/' . $rows[1]['slug'], ['preview' => $tokenA])->status);
    }

    public function test_a_stray_bad_preview_on_a_live_url_still_shows_it(): void
    {
        $c = $this->makeCollection('posts');
        $this->actingAs('admin');
        $this->post('/admin/collections/posts/entries', ['title' => 'Published Post', 'status' => 'published']);
        $slug = (string) $this->db->selectOne('SELECT slug FROM nb_entries WHERE collection_id = :c ORDER BY id DESC LIMIT 1', ['c' => $c->id])['slug'];

        $response = $this->get('/posts/' . $slug, ['preview' => 'garbage']);
        self::assertSame(200, $response->status, 'a stray ?preview must not hide a published page');
        self::assertStringNotContainsString('nb-preview-banner', $response->body, 'and it is not a preview render');
    }

    // ------------------------------------------------------------ headless API

    public function test_the_headless_preview_returns_the_draft_json(): void
    {
        $d        = $this->draft('Secret Draft');
        $response = $this->apiGet('/api/v1/preview', ['token' => $this->token($d['collection_id'], $d['id'])]);

        self::assertSame(200, $response->status);
        self::assertSame('no-store', $response->header('Cache-Control'));
        $body = json_decode($response->body, true);
        self::assertSame('Secret Draft', $body['data']['title'] ?? null);
    }

    public function test_the_headless_preview_404s_a_bad_token(): void
    {
        self::assertSame(404, $this->apiGet('/api/v1/preview', ['token' => 'nope'])->status);
        self::assertSame(404, $this->apiGet('/api/v1/preview', [])->status);
    }

    public function test_a_preview_token_is_not_usable_as_an_api_token(): void
    {
        $d = $this->draft('Secret Draft');
        $t = $this->token($d['collection_id'], $d['id']);
        // The preview token is not an API token — presenting it as a Bearer on the
        // real entries endpoint is a 401, so it can never list or read the collection.
        self::assertSame(401, $this->apiGet('/api/v1/collections/posts/entries', [], $t)->status);
    }

    // ------------------------------------------------------------ admin mint

    public function test_the_editor_shows_a_preview_button_for_a_saved_entry(): void
    {
        $d = $this->draft('Secret Draft');
        self::assertStringContainsString('Preview draft', $this->get('/admin/collections/posts/entries/' . $d['id'] . '/edit')->body);
    }

    public function test_minting_redirects_to_the_preview_url(): void
    {
        $d        = $this->draft('Secret Draft');
        $response = $this->post('/admin/collections/posts/entries/' . $d['id'] . '/preview');

        self::assertSame(302, $response->status);
        self::assertStringStartsWith('/posts/' . $d['slug'] . '?preview=', (string) $response->header('Location'));
    }

    public function test_minting_requires_csrf(): void
    {
        $d       = $this->draft('Secret Draft');
        $before  = (int) $this->db->selectOne('SELECT COUNT(*) n FROM nb_preview_tokens')['n'];
        $this->postWithoutCsrf('/admin/collections/posts/entries/' . $d['id'] . '/preview');
        $after   = (int) $this->db->selectOne('SELECT COUNT(*) n FROM nb_preview_tokens')['n'];

        self::assertSame($before, $after, 'a forged mint must not create a token');
    }

    // ------------------------------------------------------------ the token

    public function test_the_token_is_stored_hashed_not_in_plaintext(): void
    {
        $d     = $this->draft('Secret Draft');
        $token = $this->token($d['collection_id'], $d['id']);
        $row   = $this->db->selectOne('SELECT token_hash FROM nb_preview_tokens ORDER BY id DESC LIMIT 1');

        self::assertNotSame($token, $row['token_hash']);
        self::assertSame(hash('sha256', $token), $row['token_hash']);
    }

    public function test_an_expired_token_does_not_resolve(): void
    {
        $d    = $this->draft('Secret Draft');
        $svc  = new PreviewTokens($this->db);
        $tok  = $svc->issue($d['collection_id'], $d['id'], null, 1);
        // Backdate it well past expiry.
        $this->db->execute('UPDATE nb_preview_tokens SET expires_at = :e WHERE token_hash = :h', ['e' => '2000-01-01 00:00:00', 'h' => hash('sha256', $tok)]);

        self::assertNull($svc->resolve($tok));
        self::assertSame(404, $this->get('/posts/' . $d['slug'], ['preview' => $tok])->status);
    }
}

<?php

declare(strict_types=1);

namespace Nimbus\Tests\Http;

use Nimbus\Api\ApiTokenRepository;
use Nimbus\Http\Request;
use Nimbus\Http\Response;

/**
 * Entry-write input validation (DATA-2 / DATA-3 / ADMIN-6): malformed or oversized
 * input becomes a structured 422 through the shared `EntryService::save`, never an
 * uncaught 500 or an unbounded DB write. Covered on the API path (a scoped token
 * is the DoS-reachable surface) and the admin form.
 */
final class EntryValidationTest extends HttpTestCase
{
    /**
     * @param array<string,mixed> $body
     */
    private function apiWrite(string $path, array $body, string $token): Response
    {
        $server = ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_AUTHORIZATION' => 'Bearer ' . $token];
        return $this->throughKernel(new Request('POST', $path, [], [], $server, [], null, json_encode($body, JSON_THROW_ON_ERROR)));
    }

    /** @param array<int,array{handle:string,label:string,type:string,required:bool,options:array<string,mixed>}> $fields */
    private function collectionAndToken(array $fields = []): string
    {
        $this->makeCollection('posts', $fields);
        return (new ApiTokenRepository($this->db))->create('W', ['posts:write', 'posts:read']);
    }

    // ------------------------------------------------------- published_at (DATA-2/ADMIN-6)

    public function test_a_malformed_published_at_is_a_422_not_a_500_on_the_api(): void
    {
        $token = $this->collectionAndToken();
        $res   = $this->apiWrite('/api/v1/collections/posts/entries', [
            'title' => 'X', 'status' => 'published', 'published_at' => 'soon',
        ], $token);

        self::assertSame(422, $res->status, 'structured validation error, never a 500');
        self::assertStringContainsString('published_at', $res->body);
        self::assertSame(0, $this->entryCount($this->db->selectOne("SELECT id FROM nb_collections WHERE handle='posts'")['id']), 'nothing persisted');
    }

    public function test_a_malformed_published_at_re_renders_the_admin_form_blank(): void
    {
        $posts = $this->makeCollection('posts');
        $this->actingAs('admin');

        $res = $this->post('/admin/collections/posts/entries', ['title' => 'Draft?', 'status' => 'published', 'published_at' => 'banana']);

        self::assertSame(200, $res->status, 're-rendered, not 302-success and not 500');
        self::assertStringContainsString('valid publish', $res->body, 'the field error is shown');
        self::assertStringNotContainsString('1970-01-01', $res->body, 'not re-rendered as the epoch');
        self::assertSame(0, $this->entryCount($posts->id));
    }

    public function test_a_valid_future_published_at_still_schedules(): void
    {
        $token  = $this->collectionAndToken();
        $future = date('Y-m-d H:i:s', time() + 86400);
        $res    = $this->apiWrite('/api/v1/collections/posts/entries', [
            'title' => 'Later', 'status' => 'published', 'published_at' => $future,
        ], $token);

        self::assertSame(201, $res->status, 'a legitimate publish time is unaffected');
    }

    public function test_a_draft_ignores_a_garbage_published_at(): void
    {
        // Deliberate asymmetry: the guard only fires when publishing (resolvePublishedAt
        // ignores a draft's requested time), so a draft with a junk time still saves.
        $token = $this->collectionAndToken();
        $res   = $this->apiWrite('/api/v1/collections/posts/entries', [
            'title' => 'A draft', 'status' => 'draft', 'published_at' => 'whenever',
        ], $token);

        self::assertSame(201, $res->status);
    }

    // ---------------------------------------------------------- length (DATA-3)

    public function test_an_over_long_title_is_a_422_and_a_long_title_never_500s_on_the_slug(): void
    {
        $token = $this->collectionAndToken();

        // 256 chars → rejected.
        $tooLong = $this->apiWrite('/api/v1/collections/posts/entries', ['title' => str_repeat('a', 256), 'status' => 'draft'], $token);
        self::assertSame(422, $tooLong->status);
        self::assertStringContainsString('title', $tooLong->body);

        // A 255-char title is fine and yields a slug within the 191 column width.
        $ok = $this->apiWrite('/api/v1/collections/posts/entries', ['title' => str_repeat('b', 255), 'status' => 'draft'], $token);
        self::assertSame(201, $ok->status);
        $slug = (string) $this->db->selectOne('SELECT slug FROM nb_entries ORDER BY id DESC LIMIT 1')['slug'];
        self::assertLessThanOrEqual(191, mb_strlen($slug), 'the derived slug is trimmed to the column width');
    }

    public function test_colliding_long_titles_still_yield_a_slug_within_the_column(): void
    {
        $token = $this->collectionAndToken();
        $title = str_repeat('c', 255);
        $this->apiWrite('/api/v1/collections/posts/entries', ['title' => $title, 'status' => 'draft'], $token);
        $this->apiWrite('/api/v1/collections/posts/entries', ['title' => $title, 'status' => 'draft'], $token);

        foreach ($this->db->select('SELECT slug FROM nb_entries') as $row) {
            self::assertLessThanOrEqual(191, mb_strlen((string) $row['slug']), 'suffix headroom keeps every slug ≤ column width');
        }
        self::assertSame(2, (int) $this->db->selectOne('SELECT COUNT(*) AS c FROM nb_entries')['c'], 'both saved (no 500 on the collision)');
    }

    public function test_an_over_long_text_field_is_a_422(): void
    {
        $token = $this->collectionAndToken([$this->field('body', 'text')]);
        $res   = $this->apiWrite('/api/v1/collections/posts/entries', [
            'title' => 'X', 'status' => 'draft', 'fields' => ['body' => str_repeat('x', 256)],
        ], $token);

        self::assertSame(422, $res->status);
        self::assertStringContainsString('body', $res->body);
    }

    public function test_an_uncapped_scalar_type_url_is_bounded_by_the_hard_ceiling(): void
    {
        // A3: url's validate is filter_var (no length) — the Validator's universal
        // scalar ceiling is what stops a multi-MB URL from reaching the JSON column.
        $token = $this->collectionAndToken([$this->field('link', 'url')]);
        $huge  = 'https://e.test/' . str_repeat('a', 100_001);
        $res   = $this->apiWrite('/api/v1/collections/posts/entries', [
            'title' => 'X', 'status' => 'draft', 'fields' => ['link' => $huge],
        ], $token);

        self::assertSame(422, $res->status, 'the hard scalar ceiling caps url/email too');
    }

    /**
     * @param array<string,mixed> $options
     * @return array{handle:string,label:string,type:string,required:bool,options:array<string,mixed>}
     */
    private function field(string $handle, string $type, array $options = []): array
    {
        return ['handle' => $handle, 'label' => ucfirst($handle), 'type' => $type, 'required' => false, 'options' => $options];
    }

    // ---------------------------------------------- relation cardinality DoS (DATA-3)

    public function test_too_many_relation_targets_is_a_422_with_nothing_persisted(): void
    {
        $this->makeCollection('cats');
        $this->makeCollection('posts', [$this->field('rel', 'relation', ['target' => 'cats', 'multiple' => true])]);
        $token = (new ApiTokenRepository($this->db))->create('W', ['posts:write', 'posts:read', 'cats:read']);

        $ids = range(1, 101); // over the cap
        $res = $this->apiWrite('/api/v1/collections/posts/entries', [
            'title' => 'Spammy', 'status' => 'draft', 'fields' => ['rel' => $ids],
        ], $token);

        self::assertSame(422, $res->status, 'the cardinality cap rejects before any DB write');
        $postsId = (int) $this->db->selectOne("SELECT id FROM nb_collections WHERE handle='posts'")['id'];
        self::assertSame(0, $this->entryCount($postsId), 'no entry created');
        self::assertSame(0, (int) $this->db->selectOne('SELECT COUNT(*) AS c FROM nb_relations')['c'], 'zero relation rows — the write-amplification is bounded');
    }
}

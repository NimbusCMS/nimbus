<?php

declare(strict_types=1);

namespace Nimbus\Tests\Http;

use Nimbus\Api\ApiAuthContext;
use Nimbus\Api\ApiTokenRepository;
use Nimbus\Application;
use Nimbus\Content\Collection;
use Nimbus\Content\EntryInput;
use Nimbus\Content\EntryRepository;
use Nimbus\Content\EntryService;
use Nimbus\Content\FieldTypeRegistry;
use Nimbus\Content\RelationRepository;
use Nimbus\Http\Request;
use Nimbus\Http\Response;
use Nimbus\Media\MediaRepository;
use Nimbus\Support\EventDispatcher;

/**
 * The read-only headless API, through the real kernel.
 *
 * Auth is a bearer token; the API serves only the live set. These drive real
 * Requests (with an Authorization header) through Application and assert on the
 * JSON that comes back.
 */
final class ApiRoutesTest extends HttpTestCase
{
    private ApiTokenRepository $tokens;
    private EntryService $entryService;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tokens       = new ApiTokenRepository($this->db);
        // Read-all: these read tests reach across collections. (Before ADR 0011
        // an empty-abilities token implied read-all; that grant was removed, so
        // the scope is now explicit.)
        $this->token        = $this->tokens->create('Test app', ['*:read']);
        $this->entryService = new EntryService(
            $this->db,
            new EntryRepository($this->db),
            new RelationRepository($this->db),
            new FieldTypeRegistry(),
            new EventDispatcher(),
        );
    }

    /** GET the API with a bearer token (the valid one unless overridden). */
    private function api(string $path, ?string $token = null): Response
    {
        $server = ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_AUTHORIZATION' => 'Bearer ' . ($token ?? $this->token)];
        return $this->throughKernel($this->apiRequest($path, $server));
    }

    private function apiNoAuth(string $path): Response
    {
        return $this->throughKernel($this->apiRequest($path, ['REMOTE_ADDR' => '127.0.0.1']));
    }

    /** @param array<string,string> $server */
    private function apiRequest(string $path, array $server): Request
    {
        // Split ?query the way Request::fromGlobals would — the router matches
        // on the path, and the handler reads the parsed query.
        $parsed = [];
        if (str_contains($path, '?')) {
            [$path, $qs] = explode('?', $path, 2);
            parse_str($qs, $parsed);
        }
        $query = [];
        foreach ($parsed as $key => $value) {
            $query[(string) $key] = $value; // query-string keys are always strings
        }
        return new Request('GET', $path, $query, [], $server, []);
    }

    /** @return array<string,mixed> */
    private function json(Response $response): array
    {
        return json_decode($response->body, true);
    }

    /**
     * @param array<string,mixed> $options
     * @return array{handle:string,label:string,type:string,required:bool,options:array<string,mixed>}
     */
    private function field(string $handle, string $type = 'text', array $options = []): array
    {
        return ['handle' => $handle, 'label' => ucfirst($handle), 'type' => $type, 'required' => false, 'options' => $options];
    }

    /**
     * @param array<string,mixed> $values
     * @return int the saved entry id
     */
    private function publish(Collection $c, string $title, string $slug, string $status = 'published', ?string $at = null, array $values = []): int
    {
        return (int) $this->entryService->save($c, new EntryInput($title, $slug, $status, $values, $at), null, null)->entryId;
    }

    // -------------------------------------------------------- authentication

    public function test_requests_without_a_token_are_rejected(): void
    {
        $this->makeCollection('posts');

        $response = $this->apiNoAuth('/api/v1/collections/posts/entries');

        self::assertSame(401, $response->status);
        self::assertSame('application/json; charset=UTF-8', $response->header('Content-Type'));
        self::assertSame('Bearer', $response->header('WWW-Authenticate'));
        self::assertSame(401, $this->json($response)['error']['status']);
    }

    public function test_an_invalid_token_is_rejected(): void
    {
        $this->makeCollection('posts');

        $response = $this->api('/api/v1/collections/posts/entries', 'nbt_' . str_repeat('0', 40));

        self::assertSame(401, $response->status);
    }

    public function test_a_valid_token_is_accepted_and_stamped(): void
    {
        $this->makeCollection('posts');

        $response = $this->api('/api/v1/collections/posts/entries');

        self::assertSame(200, $response->status);
        // Using the token records last_used_at.
        self::assertNotNull($this->tokens->findByPlaintext($this->token)->lastUsedAt);
    }

    public function test_an_expired_token_is_rejected(): void
    {
        $this->makeCollection('posts');
        $expired = $this->tokens->create('Old', [], date('Y-m-d H:i:s', strtotime('-1 hour')));

        self::assertSame(401, $this->api('/api/v1/collections/posts/entries', $expired)->status);
    }

    public function test_a_revoked_token_is_rejected(): void
    {
        $this->makeCollection('posts');
        $plain = $this->tokens->create('Leaked');
        $this->tokens->revoke($this->tokens->findByPlaintext($plain)->id);

        self::assertSame(401, $this->api('/api/v1/collections/posts/entries', $plain)->status);
    }

    public function test_a_paused_token_is_rejected_then_accepted_after_resume(): void
    {
        $this->makeCollection('posts');
        $plain = $this->tokens->create('Paused', ['posts:read']);
        $id    = $this->tokens->findByPlaintext($plain)->id;

        $this->tokens->pause($id);
        self::assertSame(401, $this->api('/api/v1/collections/posts/entries', $plain)->status, 'a paused token is turned away');

        $this->tokens->resume($id);
        self::assertSame(200, $this->api('/api/v1/collections/posts/entries', $plain)->status, 'resuming restores access');
    }

    public function test_a_valid_token_establishes_the_principal(): void
    {
        $this->makeCollection('posts');
        $context = new ApiAuthContext();
        $app     = new Application($this->db, $this->auth, null, null, null, $context);

        $app->handle($this->apiRequest('/api/v1/collections/posts/entries', [
            'REMOTE_ADDR'        => '127.0.0.1',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->token,
        ]));

        self::assertNotNull($context->principal(), 'the middleware establishes the principal for the controller');
        self::assertSame('Test app', $context->principal()->name);
        self::assertSame($this->tokens->findByPlaintext($this->token)->id, $context->principal()->tokenId);
    }

    public function test_an_unauthenticated_request_establishes_no_principal(): void
    {
        $this->makeCollection('posts');
        $context = new ApiAuthContext();
        $app     = new Application($this->db, $this->auth, null, null, null, $context);

        $app->handle($this->apiRequest('/api/v1/collections/posts/entries', ['REMOTE_ADDR' => '127.0.0.1']));

        self::assertNull($context->principal());
    }

    // ---------------------------------------------------------- scope matrix

    /**
     * The authorization matrix: token scopes × collection × expected result.
     * Asserts the deny paths, not just the happy ones.
     */
    public function test_scope_enforcement_matrix(): void
    {
        $this->makeCollection('posts');
        $this->makeCollection('pages');

        $cases = [
            // scopes,          handle,  expected
            [['*:read'],        'posts', 200],
            [['*:read'],        'pages', 200],
            [['posts:read'],    'posts', 200],
            [['posts:read'],    'pages', 403],
            [['pages:read'],    'posts', 403],
            [[],                'posts', 403], // no scopes → deny (ADR 0011: legacy read-all grant removed)
        ];

        foreach ($cases as [$scopes, $handle, $expected]) {
            $token  = $this->tokens->create('t-' . implode('-', $scopes ?: ['legacy']), $scopes);
            $status = $this->api("/api/v1/collections/{$handle}/entries", $token)->status;
            self::assertSame($expected, $status, sprintf('scopes [%s] reading /%s', implode(',', $scopes), $handle));
        }
    }

    public function test_an_out_of_scope_handle_cannot_be_told_from_a_missing_one(): void
    {
        $this->makeCollection('pages');
        $token = $this->tokens->create('narrow', ['posts:read']);

        // An existing out-of-scope collection and a nonexistent one both answer
        // 403, so a narrow token cannot enumerate what exists outside its scope.
        self::assertSame(403, $this->api('/api/v1/collections/pages/entries', $token)->status);
        self::assertSame(403, $this->api('/api/v1/collections/ghost/entries', $token)->status);
    }

    public function test_a_relation_to_an_out_of_scope_collection_leaks_nothing(): void
    {
        $people = $this->makeCollection('people');
        $alice  = $this->publish($people, 'Alice', 'alice');
        $posts  = $this->makeCollection('posts', [$this->relation('authors', 'people')]);
        $this->publish($posts, 'Post', 'post', 'published', null, ['authors' => [$alice]]);

        // A token that can read posts but not people gets the post — with its
        // relation contributing nothing, not even the author's id.
        $scoped = $this->tokens->create('posts-only', ['posts:read']);
        $data   = $this->json($this->api('/api/v1/collections/posts/entries/post', $scoped))['data'];
        self::assertSame([], $data['fields']['authors'], 'an out-of-scope relation expands to nothing');

        // A broad token still expands it.
        $broad = $this->tokens->create('broad', ['*:read']);
        $data  = $this->json($this->api('/api/v1/collections/posts/entries/post', $broad))['data'];
        self::assertSame([['id' => $alice, 'slug' => 'alice', 'title' => 'Alice']], $data['fields']['authors']);
    }

    // ------------------------------------------------------------- openapi

    public function test_the_openapi_spec_is_served_to_an_authenticated_client(): void
    {
        $this->makeCollection('posts');

        $response = $this->api('/api/v1/openapi.json');

        self::assertSame(200, $response->status);
        self::assertStringContainsString('"openapi":"3.0.3"', $response->body);
        self::assertStringContainsString('/collections/posts/entries', $response->body);
    }

    public function test_the_openapi_spec_needs_a_token(): void
    {
        self::assertSame(401, $this->apiNoAuth('/api/v1/openapi.json')->status);
    }

    public function test_the_openapi_spec_is_scoped_to_the_presenting_token(): void
    {
        $this->makeCollection('posts');
        $this->makeCollection('secret');
        $scoped = $this->tokens->create('posts-only', ['posts:read']);

        $body = $this->api('/api/v1/openapi.json', $scoped)->body;

        self::assertStringContainsString('/collections/posts/entries', $body);
        // The spec must not enumerate a collection the token gets 403==404 on.
        self::assertStringNotContainsString('secret', $body);
    }

    // --------------------------------------------------------- published-only

    public function test_only_live_entries_are_served(): void
    {
        $c = $this->makeCollection('posts');
        $this->publish($c, 'Live', 'live');
        $this->publish($c, 'Draft', 'draft-one', 'draft');
        $this->publish($c, 'Later', 'later', 'published', (new \DateTimeImmutable('+2 days'))->format('Y-m-d H:i:s'));
        $this->publish($c, 'Archived', 'gone', 'archived');

        $data = $this->json($this->api('/api/v1/collections/posts/entries'))['data'];

        self::assertCount(1, $data, 'only the live entry is exposed');
        self::assertSame('live', $data[0]['slug']);
    }

    public function test_a_draft_cannot_be_fetched_by_slug(): void
    {
        $c = $this->makeCollection('posts');
        $this->publish($c, 'Secret', 'secret', 'draft');

        $response = $this->api('/api/v1/collections/posts/entries/secret');

        self::assertSame(404, $response->status, 'a draft must be indistinguishable from absent');
    }

    public function test_a_scheduled_entry_cannot_be_fetched_by_slug(): void
    {
        $c = $this->makeCollection('posts');
        $this->publish($c, 'Soon', 'soon', 'published', (new \DateTimeImmutable('+1 day'))->format('Y-m-d H:i:s'));

        self::assertSame(404, $this->api('/api/v1/collections/posts/entries/soon')->status);
    }

    // ---------------------------------------------------------- serialization

    public function test_a_single_entry_is_serialized_through_field_toapi(): void
    {
        $c = $this->makeCollection('posts', [$this->field('body', 'textarea'), $this->field('rank', 'number')]);
        $this->publish($c, 'Hello', 'hello', 'published', null, ['body' => 'the body', 'rank' => 5]);

        $data = $this->json($this->api('/api/v1/collections/posts/entries/hello'))['data'];

        self::assertSame('hello', $data['slug']);
        self::assertSame('Hello', $data['title']);
        self::assertNotNull($data['published_at'], 'a live entry carries its publish time');
        self::assertSame('the body', $data['fields']['body']);
        self::assertSame(5, $data['fields']['rank'], 'number stays a number through toApi');
    }

    public function test_a_single_entry_read_carries_an_etag_that_bumps_on_edit(): void
    {
        $c  = $this->makeCollection('posts');
        $id = $this->publish($c, 'Hello', 'hello');

        $etag1 = $this->api('/api/v1/collections/posts/entries/hello')->header('ETag');
        self::assertNotNull($etag1);
        self::assertMatchesRegularExpression('/^"\d+-\d+"$/', (string) $etag1, 'a strong id-version ETag');

        // An edit bumps the stored version…
        $this->entryService->save($c, new EntryInput('Hello again', 'hello', 'published', []), $id, null);
        self::assertSame(2, (int) (new EntryRepository($this->db))->find($c->id, $id)['version']);

        // …so the ETag the read returns changes.
        $etag2 = $this->api('/api/v1/collections/posts/entries/hello')->header('ETag');
        self::assertNotSame($etag1, $etag2, 'the ETag changes when the entry changes');
    }

    public function test_published_at_is_iso_8601(): void
    {
        $c = $this->makeCollection('posts');
        $this->publish($c, 'Hello', 'hello');

        $data = $this->json($this->api('/api/v1/collections/posts/entries/hello'))['data'];

        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $data['published_at']);
    }

    public function test_a_boolean_field_serializes_as_a_json_boolean(): void
    {
        $c = $this->makeCollection('posts', [$this->field('featured', 'boolean')]);
        $this->publish($c, 'On', 'on', 'published', null, ['featured' => '1']);
        $this->publish($c, 'Off', 'off', 'published', null, ['featured' => '0']);

        $on = $this->json($this->api('/api/v1/collections/posts/entries/on'))['data'];
        self::assertTrue($on['fields']['featured'], 'a set toggle is true, not 1');

        $off = $this->json($this->api('/api/v1/collections/posts/entries/off'))['data'];
        self::assertFalse($off['fields']['featured'], 'an unset toggle is false, not 0');
    }

    // ------------------------------------------------------------ pagination

    public function test_pagination_meta_and_pages(): void
    {
        $c = $this->makeCollection('posts');
        for ($i = 1; $i <= 5; $i++) {
            $this->publish($c, "Post {$i}", "post-{$i}", 'published', (new \DateTimeImmutable("-{$i} days"))->format('Y-m-d H:i:s'));
        }

        $body = $this->json($this->api('/api/v1/collections/posts/entries?per_page=2&page=1'));

        self::assertCount(2, $body['data']);
        self::assertSame(['page' => 1, 'per_page' => 2, 'total' => 5, 'total_pages' => 3], $body['meta']);

        $page3 = $this->json($this->api('/api/v1/collections/posts/entries?per_page=2&page=3'));
        self::assertCount(1, $page3['data'], 'the last page is partial');
    }

    public function test_per_page_is_capped(): void
    {
        $c = $this->makeCollection('posts');

        $meta = $this->json($this->api('/api/v1/collections/posts/entries?per_page=9999'))['meta'];

        self::assertSame(50, $meta['per_page'], 'a single response stays bounded');
    }

    public function test_bad_pagination_falls_back_to_defaults(): void
    {
        $c = $this->makeCollection('posts');

        $meta = $this->json($this->api('/api/v1/collections/posts/entries?per_page=-1&page=0'))['meta'];

        self::assertSame(20, $meta['per_page']);
        self::assertSame(1, $meta['page']);
    }

    // --------------------------------------------------------------- media

    public function test_a_media_field_is_expanded_to_a_full_object(): void
    {
        $media = new MediaRepository($this->db);
        $mid   = $media->create([
            'filename' => 'hero.png', 'path' => '2026/08/x.png', 'url' => '/uploads/2026/08/x.png',
            'mime' => 'image/png', 'size' => 2048, 'width' => 800, 'height' => 600, 'alt' => 'A hero',
        ], null);

        $c = $this->makeCollection('posts', [$this->field('image', 'media')]);
        $this->publish($c, 'Post', 'post', 'published', null, ['image' => $mid]);

        $data = $this->json($this->api('/api/v1/collections/posts/entries/post'))['data'];

        self::assertSame([
            'id' => $mid, 'url' => '/uploads/2026/08/x.png', 'alt' => 'A hero',
            'mime' => 'image/png', 'width' => 800, 'height' => 600,
        ], $data['fields']['image'], 'the client gets the URL without a second request');
    }

    public function test_an_unset_media_field_is_null(): void
    {
        $c = $this->makeCollection('posts', [$this->field('image', 'media')]);
        $this->publish($c, 'Post', 'post', 'published', null, ['image' => null]);

        $data = $this->json($this->api('/api/v1/collections/posts/entries/post'))['data'];

        self::assertNull($data['fields']['image']);
    }

    public function test_a_deleted_media_reference_reads_as_null_not_an_error(): void
    {
        $c = $this->makeCollection('posts', [$this->field('image', 'media')]);
        // Reference an id that does not exist — a file deleted after the entry.
        $this->publish($c, 'Post', 'post', 'published', null, ['image' => 4242]);

        $response = $this->api('/api/v1/collections/posts/entries/post');

        self::assertSame(200, $response->status, 'a dangling media reference must not error a public request');
        self::assertNull($this->json($response)['data']['fields']['image']);
    }

    // -------------------------------------------------------------- relations

    /** @return array{handle:string,label:string,type:string,required:bool,options:array<string,mixed>} */
    private function relation(string $handle, string $target): array
    {
        return $this->field($handle, 'relation', ['target' => $target, 'multiple' => true]);
    }

    public function test_a_relation_field_expands_to_live_target_objects_in_link_order(): void
    {
        $people  = $this->makeCollection('people');
        $aliceId = $this->publish($people, 'Alice', 'alice');
        $bobId   = $this->publish($people, 'Bob', 'bob');

        $posts = $this->makeCollection('posts', [$this->relation('authors', 'people')]);
        // Link Bob before Alice — the API must preserve link order, not id order.
        $this->publish($posts, 'Post', 'post', 'published', null, ['authors' => [$bobId, $aliceId]]);

        $data = $this->json($this->api('/api/v1/collections/posts/entries/post'))['data'];

        self::assertSame([
            ['id' => $bobId, 'slug' => 'bob', 'title' => 'Bob'],
            ['id' => $aliceId, 'slug' => 'alice', 'title' => 'Alice'],
        ], $data['fields']['authors'], 'each target expands to a usable object, in link order');
    }

    public function test_a_relation_to_a_non_live_target_leaks_nothing(): void
    {
        $people  = $this->makeCollection('people');
        $liveId  = $this->publish($people, 'Published', 'pub');
        $draftId = $this->publish($people, 'Secret', 'secret', 'draft');
        $laterId = $this->publish($people, 'Later', 'later', 'published', (new \DateTimeImmutable('+2 days'))->format('Y-m-d H:i:s'));

        $posts = $this->makeCollection('posts', [$this->relation('authors', 'people')]);
        $this->publish($posts, 'Post', 'post', 'published', null, ['authors' => [$liveId, $draftId, $laterId]]);

        $data = $this->json($this->api('/api/v1/collections/posts/entries/post'))['data'];

        self::assertSame(
            [['id' => $liveId, 'slug' => 'pub', 'title' => 'Published']],
            $data['fields']['authors'],
            'a link to a draft or a not-yet-due entry contributes nothing — not even its existence',
        );
    }

    public function test_an_unlinked_relation_field_is_an_empty_list(): void
    {
        $this->makeCollection('people');
        $posts = $this->makeCollection('posts', [$this->relation('authors', 'people')]);
        $this->publish($posts, 'Post', 'post', 'published', null, ['authors' => []]);

        $data = $this->json($this->api('/api/v1/collections/posts/entries/post'))['data'];

        self::assertSame([], $data['fields']['authors']);
    }

    // ------------------------------------------------------------ not found

    public function test_unknown_collection_is_a_json_404(): void
    {
        $response = $this->api('/api/v1/collections/nope/entries');

        self::assertSame(404, $response->status);
        self::assertSame('application/json; charset=UTF-8', $response->header('Content-Type'));
        self::assertStringContainsString('nope', $this->json($response)['error']['message']);
    }

    public function test_every_error_carries_a_stable_machine_readable_code(): void
    {
        $this->makeCollection('posts');
        $this->makeCollection('pages');

        // 401 unauthenticated
        self::assertSame('unauthorized', $this->json($this->apiNoAuth('/api/v1/collections/posts/entries'))['error']['code']);

        // 403 out-of-scope (a token scoped to posts, asking for pages)
        $narrow    = $this->tokens->create('narrow', ['posts:read']);
        $forbidden = $this->json($this->api('/api/v1/collections/pages/entries', $narrow));
        self::assertSame('forbidden', $forbidden['error']['code']);

        // 404 absent (a broad token, so it is a real not-found and not a scope 403)
        $notFound = $this->json($this->api('/api/v1/collections/ghost/entries'));
        self::assertSame('not_found', $notFound['error']['code']);

        // The envelope is uniform: always status + code + message, in that order.
        foreach ([$forbidden, $notFound] as $error) {
            self::assertSame(['status', 'code', 'message'], array_keys($error['error']));
        }
    }

    public function test_an_empty_collection_returns_an_empty_page_not_an_error(): void
    {
        $this->makeCollection('posts');

        $body = $this->json($this->api('/api/v1/collections/posts/entries'));

        self::assertSame([], $body['data']);
        self::assertSame(0, $body['meta']['total']);
        self::assertSame(0, $body['meta']['total_pages']);
    }
}

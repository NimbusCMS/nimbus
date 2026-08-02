<?php

declare(strict_types=1);

namespace Nimbus\Tests\Http;

use Nimbus\Api\ApiTokenRepository;
use Nimbus\Content\Collection;
use Nimbus\Content\EntryInput;
use Nimbus\Content\EntryRepository;
use Nimbus\Content\EntryService;
use Nimbus\Content\FieldTypeRegistry;
use Nimbus\Content\RelationRepository;
use Nimbus\Http\Request;
use Nimbus\Http\Response;
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
        $this->token        = $this->tokens->create('Test app');
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
        $query = [];
        if (str_contains($path, '?')) {
            [$path, $qs] = explode('?', $path, 2);
            parse_str($qs, $query);
        }
        return new Request('GET', $path, $query, [], $server, []);
    }

    /** @return array<string,mixed> */
    private function json(Response $response): array
    {
        return json_decode($response->body, true);
    }

    /** @return array{handle:string,label:string,type:string,required:bool,options:array<string,mixed>} */
    private function field(string $handle, string $type = 'text'): array
    {
        return ['handle' => $handle, 'label' => ucfirst($handle), 'type' => $type, 'required' => false, 'options' => []];
    }

    /** @param array<string,mixed> $values */
    private function publish(Collection $c, string $title, string $slug, string $status = 'published', ?string $at = null, array $values = []): void
    {
        $this->entryService->save($c, new EntryInput($title, $slug, $status, $values, $at), null, null);
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

    public function test_published_at_is_iso_8601(): void
    {
        $c = $this->makeCollection('posts');
        $this->publish($c, 'Hello', 'hello');

        $data = $this->json($this->api('/api/v1/collections/posts/entries/hello'))['data'];

        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $data['published_at']);
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

    // ------------------------------------------------------------ not found

    public function test_unknown_collection_is_a_json_404(): void
    {
        $response = $this->api('/api/v1/collections/nope/entries');

        self::assertSame(404, $response->status);
        self::assertSame('application/json; charset=UTF-8', $response->header('Content-Type'));
        self::assertStringContainsString('nope', $this->json($response)['error']['message']);
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

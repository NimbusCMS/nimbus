<?php

declare(strict_types=1);

namespace Nimbus\Tests\Http;

use Nimbus\Api\ApiTokenRepository;
use Nimbus\Content\EntryRepository;
use Nimbus\Http\Request;
use Nimbus\Http\Response;

/**
 * The write API (ADR 0007): create / update / delete through the real kernel.
 * Covers the `{handle}:write` scope, If-Match concurrency, validation, and that
 * the allow-list binding keeps mass-assignment out.
 */
final class ApiWriteTest extends HttpTestCase
{
    private ApiTokenRepository $tokens;
    private EntryRepository $entries;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tokens  = new ApiTokenRepository($this->db);
        $this->entries = new EntryRepository($this->db);
    }

    /**
     * @param array<string,mixed>  $body
     * @param array<string,string> $headers e.g. ['If-Match' => '"3-1"']
     */
    private function send(string $method, string $path, array $body, ?string $token, array $headers = []): Response
    {
        $server = ['REMOTE_ADDR' => '127.0.0.1'];
        if ($token !== null) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
        }
        foreach ($headers as $name => $value) {
            $server['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
        }
        $request = new Request($method, $path, [], [], $server, [], null, json_encode($body, JSON_THROW_ON_ERROR));
        return $this->throughKernel($request);
    }

    /**
     * @param array<string,mixed> $options
     * @return array{handle:string,label:string,type:string,required:bool,options:array<string,mixed>}
     */
    private function field(string $handle, string $type = 'text', bool $required = false, array $options = []): array
    {
        return ['handle' => $handle, 'label' => ucfirst($handle), 'type' => $type, 'required' => $required, 'options' => $options];
    }

    /** @return array<string,mixed> the decoded `data` of a success response */
    private function data(Response $response): array
    {
        return json_decode($response->body, true)['data'];
    }

    // ------------------------------------------------------------------ create

    public function test_a_write_token_creates_an_entry(): void
    {
        $c     = $this->makeCollection('posts', [$this->field('body', 'textarea')]);
        $token = $this->tokens->create('W', ['posts:write']);

        $response = $this->send('POST', '/api/v1/collections/posts/entries', [
            'title' => 'Hello', 'status' => 'published', 'fields' => ['body' => 'hi there'],
        ], $token);

        self::assertSame(201, $response->status);
        self::assertNotNull($response->header('ETag'));
        self::assertStringContainsString('/api/v1/collections/posts/entries/', (string) $response->header('Location'));

        $data = $this->data($response);
        self::assertSame('Hello', $data['title']);
        self::assertSame('hi there', $data['fields']['body']);
        self::assertNotNull($this->entries->findBySlug($c->id, $data['slug']), 'the entry is really persisted');
    }

    public function test_creating_needs_the_write_scope(): void
    {
        $c = $this->makeCollection('posts');

        foreach (['posts:read', '*:read', ''] as $scope) {
            $token    = $this->tokens->create('T', $scope === '' ? [] : [$scope]);
            $response = $this->send('POST', '/api/v1/collections/posts/entries', ['title' => 'X', 'fields' => []], $token);

            self::assertSame(403, $response->status, "scope [{$scope}] must not create");
            self::assertSame('forbidden', json_decode($response->body, true)['error']['code']);
        }
        self::assertSame([], $this->entries->forCollection($c->id), 'nothing was created');
    }

    public function test_mass_assignment_is_ignored(): void
    {
        $c     = $this->makeCollection('posts', [$this->field('body', 'textarea')]);
        $token = $this->tokens->create('W', ['posts:write']);

        $response = $this->send('POST', '/api/v1/collections/posts/entries', [
            'title'     => 'MA',
            'author_id' => 999,                              // a privileged-looking top-level key
            'fields'    => ['body' => 'ok', 'evil' => 'x'],  // an undeclared field
        ], $token);

        self::assertSame(201, $response->status);
        self::assertArrayNotHasKey('evil', $this->data($response)['fields'], 'an undeclared field never lands');

        $row  = $this->entries->findBySlug($c->id, $this->data($response)['slug']);
        $data = is_array($row['data']) ? $row['data'] : [];
        self::assertArrayNotHasKey('evil', $data);
        self::assertNull($row['author_id'], 'author_id is not settable from the body');
    }

    public function test_a_missing_required_field_is_a_422_with_field_messages(): void
    {
        $this->makeCollection('notes', [$this->field('body', 'text', true)]);
        $token = $this->tokens->create('W', ['notes:write']);

        $response = $this->send('POST', '/api/v1/collections/notes/entries', ['title' => 'T', 'fields' => []], $token);

        self::assertSame(422, $response->status);
        $error = json_decode($response->body, true)['error'];
        self::assertSame('invalid', $error['code']);
        self::assertArrayHasKey('body', $error['fields']);
    }

    // ------------------------------------------------------------------ update

    public function test_update_requires_if_match_and_bumps_the_etag(): void
    {
        $this->makeCollection('posts', [$this->field('body', 'text'), $this->field('note', 'text')]);
        $token  = $this->tokens->create('W', ['posts:write']);
        $create = $this->send('POST', '/api/v1/collections/posts/entries', ['title' => 'Hello', 'fields' => ['body' => 'v1', 'note' => 'keep']], $token);
        $slug   = $this->data($create)['slug'];
        $etag   = (string) $create->header('ETag');
        $path   = "/api/v1/collections/posts/entries/{$slug}";

        // No If-Match → 428.
        self::assertSame(428, $this->send('PATCH', $path, ['fields' => ['body' => 'v2']], $token)->status);
        // Stale If-Match → 412.
        self::assertSame(412, $this->send('PATCH', $path, ['fields' => ['body' => 'v2']], $token, ['If-Match' => '"999-9"'])->status);

        // Matching If-Match → 200; the omitted field keeps its value (PATCH).
        $ok = $this->send('PATCH', $path, ['fields' => ['body' => 'v2']], $token, ['If-Match' => $etag]);
        self::assertSame(200, $ok->status);
        self::assertSame('v2', $this->data($ok)['fields']['body']);
        self::assertSame('keep', $this->data($ok)['fields']['note'], 'an omitted field is preserved');
        self::assertNotSame($etag, $ok->header('ETag'), 'the ETag advanced with the version');
    }

    // ------------------------------------------------------------------ delete

    public function test_delete_needs_if_match_then_removes_the_entry(): void
    {
        $c      = $this->makeCollection('posts');
        $token  = $this->tokens->create('W', ['posts:write']);
        $create = $this->send('POST', '/api/v1/collections/posts/entries', ['title' => 'Bye', 'fields' => []], $token);
        $slug   = $this->data($create)['slug'];
        $path   = "/api/v1/collections/posts/entries/{$slug}";

        self::assertSame(428, $this->send('DELETE', $path, [], $token)->status, 'a delete also needs If-Match');

        $response = $this->send('DELETE', $path, [], $token, ['If-Match' => (string) $create->header('ETag')]);
        self::assertSame(204, $response->status);
        self::assertSame('', $response->body);
        self::assertNull($this->entries->findBySlug($c->id, $slug), 'the entry is gone');
    }

    public function test_an_out_of_scope_write_cannot_be_told_from_a_missing_collection(): void
    {
        $this->makeCollection('pages');
        $token = $this->tokens->create('narrow', ['posts:write']);

        // pages exists but is out of scope; ghost does not exist — both 403.
        self::assertSame(403, $this->send('POST', '/api/v1/collections/pages/entries', ['title' => 'X', 'fields' => []], $token)->status);
        self::assertSame(403, $this->send('POST', '/api/v1/collections/ghost/entries', ['title' => 'X', 'fields' => []], $token)->status);
    }
}

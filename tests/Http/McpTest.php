<?php

declare(strict_types=1);

namespace Nimbus\Tests\Http;

use Nimbus\Api\ApiTokenRepository;
use Nimbus\Http\Request;
use Nimbus\Http\Response;

/**
 * The MCP HTTP transport (ADR 0009): JSON-RPC 2.0 over `POST /api/v1/mcp`,
 * through the real kernel so it runs behind the same bearer auth and rate limit
 * as the rest of the API. Proves the two invariants that matter most — tool
 * discovery is scope-filtered (and out-of-scope tools are *unknown*, not
 * forbidden, so nothing leaks), and writes require the entry's current version.
 */
final class McpTest extends HttpTestCase
{
    private ApiTokenRepository $tokens;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tokens = new ApiTokenRepository($this->db);
    }

    // --------------------------------------------------------------- transport

    /** @param array<string,mixed> $body */
    private function postMcp(array $body, ?string $token): Response
    {
        $server = ['REMOTE_ADDR' => '127.0.0.1'];
        if ($token !== null) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
        }
        $request = new Request('POST', '/api/v1/mcp', [], [], $server, [], null, json_encode($body, JSON_THROW_ON_ERROR));
        return $this->throughKernel($request);
    }

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed> the decoded JSON-RPC response
     */
    private function rpc(string $method, array $params, ?string $token): array
    {
        return json_decode($this->postMcp(['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params], $token)->body, true);
    }

    /**
     * @param array<string,mixed> $arguments
     * @return array<string,mixed>
     */
    private function call(string $name, array $arguments, ?string $token): array
    {
        return $this->rpc('tools/call', ['name' => $name, 'arguments' => $arguments], $token);
    }

    /**
     * The structured payload of a successful tools/call.
     *
     * @param array<string,mixed> $response
     * @return array<string,mixed>
     */
    private function structured(array $response): array
    {
        return $response['result']['structuredContent'];
    }

    /**
     * @param array<string,mixed> $response
     * @return list<string>
     */
    private function toolNames(array $response): array
    {
        return array_column($response['result']['tools'], 'name');
    }

    // ------------------------------------------------------------- handshake

    public function test_initialize_advertises_the_protocol_and_tools(): void
    {
        $token    = $this->tokens->create('R', ['posts:read']);
        $response = $this->rpc('initialize', [], $token);

        self::assertSame('2.0', $response['jsonrpc']);
        self::assertArrayHasKey('protocolVersion', $response['result']);
        self::assertSame('NimbusCMS', $response['result']['serverInfo']['name']);
        self::assertArrayHasKey('tools', $response['result']['capabilities']);
    }

    public function test_a_notification_gets_a_202_with_no_body(): void
    {
        $token    = $this->tokens->create('R', ['posts:read']);
        // No `id` → a notification. The client sends this after initialize.
        $response = $this->postMcp(['jsonrpc' => '2.0', 'method' => 'notifications/initialized'], $token);

        self::assertSame(202, $response->status);
        self::assertSame('', $response->body);
    }

    public function test_an_unknown_method_is_method_not_found(): void
    {
        $token    = $this->tokens->create('R', ['posts:read']);
        $response = $this->rpc('resources/list', [], $token);

        self::assertSame(-32601, $response['error']['code']);
    }

    // ------------------------------------------------------ scope-filtered list

    public function test_tools_list_is_filtered_to_the_tokens_scopes(): void
    {
        $this->makeCollection('posts', [$this->field('body', 'textarea')]);
        $this->makeCollection('pages', [$this->field('body', 'textarea')]);
        $token = $this->tokens->create('R', ['posts:read']);

        $names = $this->toolNames($this->rpc('tools/list', [], $token));

        self::assertContains('list_posts', $names);
        self::assertContains('get_posts', $names);
        self::assertContains('list_collections', $names, 'introspection is always offered');
        self::assertNotContains('create_posts', $names, 'a read token sees no write tools');
        self::assertNotContains('list_pages', $names, 'nor tools for a collection it cannot read');
    }

    public function test_a_write_token_sees_the_write_tools(): void
    {
        $this->makeCollection('posts', [$this->field('body', 'textarea')]);
        $token = $this->tokens->create('W', ['posts:read', 'posts:write']);

        $names = $this->toolNames($this->rpc('tools/list', [], $token));

        foreach (['create_posts', 'update_posts', 'delete_posts'] as $tool) {
            self::assertContains($tool, $names);
        }
    }

    public function test_calling_a_tool_outside_scope_is_an_unknown_tool(): void
    {
        $this->makeCollection('posts', [$this->field('body', 'textarea')]);
        $this->makeCollection('pages', [$this->field('body', 'textarea')]);
        $token = $this->tokens->create('R', ['posts:read']);

        // A write tool the token cannot use, and a tool for an unreadable
        // collection, are both reported as *unknown* — never "forbidden" — so a
        // token cannot map what exists beyond its scope.
        self::assertSame(-32602, $this->call('create_posts', ['title' => 'x'], $token)['error']['code']);
        self::assertSame(-32602, $this->call('list_pages', [], $token)['error']['code']);
    }

    // ------------------------------------------------------------ content tools

    public function test_create_then_get_round_trips_with_a_version(): void
    {
        $this->makeCollection('posts', [$this->field('body', 'textarea')]);
        $write = $this->tokens->create('W', ['posts:read', 'posts:write']);

        $created = $this->structured($this->call('create_posts', [
            'title'        => 'Hello',
            'status'       => 'published',
            'published_at' => '2020-01-01 00:00:00',
            'fields'       => ['body' => 'hi there'],
        ], $write));
        self::assertSame(1, $created['version']);
        $slug = $created['data']['slug'];

        $fetched = $this->structured($this->call('get_posts', ['slug' => $slug], $write));
        self::assertSame('Hello', $fetched['data']['title']);
        self::assertSame(1, $fetched['version'], 'get returns the version for read-before-write');
    }

    public function test_the_allow_list_binding_drops_unknown_fields_over_mcp(): void
    {
        $this->makeCollection('posts', [$this->field('body', 'textarea')]);
        $write = $this->tokens->create('W', ['posts:read', 'posts:write']);

        $created = $this->structured($this->call('create_posts', [
            'title'  => 'Guarded',
            'status' => 'published',
            'fields' => ['body' => 'ok', 'is_admin' => true],
        ], $write));

        self::assertArrayHasKey('body', $created['data']['fields']);
        self::assertArrayNotHasKey('is_admin', $created['data']['fields'], 'an undeclared field is ignored, like the HTTP API');
    }

    public function test_update_requires_the_current_version(): void
    {
        $this->makeCollection('posts', [$this->field('body', 'textarea')]);
        $write = $this->tokens->create('W', ['posts:read', 'posts:write']);

        $created = $this->structured($this->call('create_posts', [
            'title'        => 'V1',
            'status'       => 'published',
            'published_at' => '2020-01-01 00:00:00',
            'fields'       => ['body' => 'one'],
        ], $write));
        $slug = $created['data']['slug'];

        // A stale version is rejected as a tool error (not a protocol error).
        $stale = $this->call('update_posts', ['slug' => $slug, 'version' => 999, 'fields' => ['body' => 'two']], $write);
        self::assertTrue($stale['result']['isError']);
        self::assertSame('precondition_failed', $stale['result']['structuredContent']['error']['code']);

        // The current version succeeds and bumps the version.
        $ok = $this->structured($this->call('update_posts', ['slug' => $slug, 'version' => 1, 'fields' => ['body' => 'two']], $write));
        self::assertSame(2, $ok['version']);
        self::assertSame('two', $ok['data']['fields']['body']);
    }

    public function test_delete_requires_version_then_removes_the_entry(): void
    {
        $this->makeCollection('posts', [$this->field('body', 'textarea')]);
        $write = $this->tokens->create('W', ['posts:read', 'posts:write']);

        $created = $this->structured($this->call('create_posts', [
            'title'        => 'Doomed',
            'status'       => 'published',
            'published_at' => '2020-01-01 00:00:00',
            'fields'       => ['body' => 'x'],
        ], $write));
        $slug = $created['data']['slug'];

        // Missing version → a precondition-required tool error.
        $noVersion = $this->call('delete_posts', ['slug' => $slug], $write);
        self::assertTrue($noVersion['result']['isError']);
        self::assertSame('precondition_required', $noVersion['result']['structuredContent']['error']['code']);

        $deleted = $this->call('delete_posts', ['slug' => $slug, 'version' => 1], $write);
        self::assertFalse($deleted['result']['isError']);
        self::assertTrue($this->call('get_posts', ['slug' => $slug], $write)['result']['isError'], 'the entry is gone');
    }

    // ------------------------------------------------------------ introspection

    public function test_list_collections_shows_only_readable_ones(): void
    {
        $this->makeCollection('posts', [$this->field('body', 'textarea')]);
        $this->makeCollection('pages', [$this->field('body', 'textarea')]);
        $token = $this->tokens->create('R', ['posts:read']);

        $handles = array_column($this->structured($this->call('list_collections', [], $token))['collections'], 'handle');

        self::assertSame(['posts'], $handles, 'a collection the token cannot read is not listed');
    }

    public function test_describe_collection_reports_typed_fields(): void
    {
        $this->makeCollection('posts', [$this->field('body', 'textarea'), $this->field('count', 'number')]);
        $token = $this->tokens->create('R', ['posts:read']);

        $described = $this->structured($this->call('describe_collection', ['handle' => 'posts'], $token));

        self::assertSame('posts', $described['handle']);
        $handles = array_column($described['fields'], 'handle');
        self::assertContains('body', $handles);
        self::assertContains('count', $handles);
    }

    public function test_describe_collection_hides_an_unreadable_one(): void
    {
        $this->makeCollection('secret', [$this->field('body', 'textarea')]);
        $token = $this->tokens->create('R', ['posts:read']);

        // Non-enumeration: unreadable and absent look identical.
        $response = $this->call('describe_collection', ['handle' => 'secret'], $token);
        self::assertTrue($response['result']['isError']);
        self::assertSame('not_found', $response['result']['structuredContent']['error']['code']);
    }

    /**
     * @param array<string,mixed> $options
     * @return array{handle:string,label:string,type:string,required:bool,options:array<string,mixed>}
     */
    private function field(string $handle, string $type = 'text', bool $required = false, array $options = []): array
    {
        return ['handle' => $handle, 'label' => ucfirst($handle), 'type' => $type, 'required' => $required, 'options' => $options];
    }
}

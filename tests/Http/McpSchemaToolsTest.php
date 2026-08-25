<?php

declare(strict_types=1);

namespace Nimbus\Tests\Http;

use Nimbus\Api\ApiTokenRepository;
use Nimbus\Content\CollectionRepository;
use Nimbus\Http\Request;

/**
 * MCP schema tools (ADR 0009, Slice 4): structural management gated on
 * `schema:write`, driven through the real kernel. Proves the capability gate
 * (hidden + unknown-tool for a token without it), the create/field/delete
 * lifecycle through the shared CollectionService, the destructive-delete
 * confirmation, unknown-type rejection, and that the collection version bumps.
 */
final class McpSchemaToolsTest extends HttpTestCase
{
    private ApiTokenRepository $tokens;
    private CollectionRepository $collections;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tokens      = new ApiTokenRepository($this->db);
        $this->collections = new CollectionRepository($this->db);
    }

    /**
     * @param array<string,mixed> $arguments
     * @return array<string,mixed>
     */
    private function call(string $name, array $arguments, string $token): array
    {
        $server  = ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_AUTHORIZATION' => 'Bearer ' . $token];
        $body    = ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $name, 'arguments' => $arguments]];
        $request = new Request('POST', '/api/v1/mcp', [], [], $server, [], null, json_encode($body, JSON_THROW_ON_ERROR));
        return json_decode($this->throughKernel($request)->body, true);
    }

    /** @return list<string> */
    private function toolNames(string $token): array
    {
        $server  = ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_AUTHORIZATION' => 'Bearer ' . $token];
        $body    = ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => []];
        $request = new Request('POST', '/api/v1/mcp', [], [], $server, [], null, json_encode($body, JSON_THROW_ON_ERROR));
        $decoded = json_decode($this->throughKernel($request)->body, true);
        return array_column($decoded['result']['tools'], 'name');
    }

    /**
     * @param array<string,mixed> $response
     * @return array<string,mixed>
     */
    private function structured(array $response): array
    {
        return $response['result']['structuredContent'];
    }

    /** @return array<string,mixed> */
    private function field(string $label, string $type): array
    {
        return ['label' => $label, 'type' => $type];
    }

    // ---------------------------------------------------------------- gating

    public function test_schema_tools_require_the_schema_write_capability(): void
    {
        $content = $this->tokens->create('C', ['posts:read']);
        $schema  = $this->tokens->create('S', ['schema:write']);

        self::assertNotContains('create_collection', $this->toolNames($content), 'a content token never sees schema tools');
        self::assertContains('create_collection', $this->toolNames($schema));

        // Non-enumeration: to a token without schema:write the tool is *unknown*.
        self::assertSame(-32602, $this->call('create_collection', ['handle' => 'x', 'name' => 'X'], $content)['error']['code']);
    }

    // ----------------------------------------------------------- lifecycle

    public function test_create_then_add_remove_field_bumping_the_version(): void
    {
        $schema = $this->tokens->create('S', ['schema:write']);

        $created = $this->structured($this->call('create_collection', [
            'handle' => 'events', 'name' => 'Events',
            'fields' => [$this->field('When', 'date')],
        ], $schema));
        self::assertSame('created', $created['action']);
        self::assertSame(1, $created['collection']['version']);
        self::assertNotNull($this->collections->findByHandle('events'), 'the collection really exists');

        $added = $this->structured($this->call('add_field', ['handle' => 'events', 'field' => $this->field('Venue', 'text')], $schema));
        self::assertSame(2, $added['collection']['version'], 'a field change bumps the version');
        self::assertContains('venue', array_column($added['collection']['fields'], 'handle'));

        $removed = $this->structured($this->call('remove_field', ['handle' => 'events', 'field_handle' => 'venue'], $schema));
        self::assertSame(3, $removed['collection']['version']);
        self::assertNotContains('venue', array_column($removed['collection']['fields'], 'handle'));
    }

    public function test_set_fields_replaces_the_whole_set(): void
    {
        $schema = $this->tokens->create('S', ['schema:write']);
        $this->call('create_collection', ['handle' => 'menu', 'name' => 'Menu', 'fields' => [$this->field('Price', 'number'), $this->field('Spicy', 'boolean')]], $schema);

        $result = $this->structured($this->call('set_fields', ['handle' => 'menu', 'fields' => [$this->field('Calories', 'number')]], $schema));

        $handles = array_column($result['collection']['fields'], 'handle');
        self::assertSame(['calories'], $handles, 'the previous fields are gone');
    }

    public function test_an_unknown_field_type_is_rejected(): void
    {
        $schema   = $this->tokens->create('S', ['schema:write']);
        $response = $this->call('create_collection', ['handle' => 'bad', 'name' => 'Bad', 'fields' => [$this->field('Oops', 'nonsense')]], $schema);

        self::assertTrue($response['result']['isError']);
        self::assertSame('invalid', $response['result']['structuredContent']['error']['code']);
        self::assertNull($this->collections->findByHandle('bad'), 'nothing was created');
    }

    public function test_a_duplicate_handle_is_a_clean_error(): void
    {
        $schema = $this->tokens->create('S', ['schema:write']);
        $this->call('create_collection', ['handle' => 'dup', 'name' => 'One'], $schema);

        $again = $this->call('create_collection', ['handle' => 'dup', 'name' => 'Two'], $schema);
        self::assertTrue($again['result']['isError']);
        self::assertSame('invalid', $again['result']['structuredContent']['error']['code']);
    }

    public function test_a_reserved_collection_handle_is_rejected_over_mcp(): void
    {
        // FU-4 parity: a collection handle colliding with a management capability
        // is rejected on the agent surface too, and the error names the set so an
        // agent self-corrects.
        $schema   = $this->tokens->create('S', ['schema:write']);
        $response = $this->call('create_collection', ['handle' => 'media', 'name' => 'Media'], $schema);

        self::assertTrue($response['result']['isError']);
        self::assertSame('invalid', $response['result']['structuredContent']['error']['code']);
        self::assertStringContainsStringIgnoringCase('reserved', (string) $response['result']['structuredContent']['error']['message']);
        self::assertNull($this->collections->findByHandle('media'), 'nothing was created');
    }

    public function test_a_reserved_field_handle_is_rejected_over_mcp(): void
    {
        // FU-6 parity: a field named `title` is rejected via create_collection and
        // add_field.
        $schema = $this->tokens->create('S', ['schema:write']);

        $onCreate = $this->call('create_collection', ['handle' => 'posts', 'name' => 'Posts', 'fields' => [$this->field('Title', 'text')]], $schema);
        self::assertTrue($onCreate['result']['isError']);
        self::assertNull($this->collections->findByHandle('posts'));

        $this->call('create_collection', ['handle' => 'posts', 'name' => 'Posts'], $schema);
        $onAdd = $this->call('add_field', ['handle' => 'posts', 'field' => $this->field('Slug', 'text')], $schema);
        self::assertTrue($onAdd['result']['isError'], 'add_field rejects a reserved field handle');
    }

    // -------------------------------------------------------- singletons

    public function test_create_collection_can_make_a_singleton_and_reports_the_kind(): void
    {
        $schema  = $this->tokens->create('S', ['schema:write']);
        $created = $this->structured($this->call('create_collection', ['handle' => 'home', 'name' => 'Home', 'kind' => 'single'], $schema));

        self::assertSame('single', $created['collection']['kind']);
        $collection = $this->collections->findByHandle('home');
        self::assertNotNull($collection);
        self::assertTrue($collection->isSingle(), 'the collection is a singleton');
    }

    public function test_create_collection_defaults_to_a_regular_collection(): void
    {
        $schema  = $this->tokens->create('S', ['schema:write']);
        $created = $this->structured($this->call('create_collection', ['handle' => 'posts', 'name' => 'Posts'], $schema));

        self::assertSame('collection', $created['collection']['kind']);
        $collection = $this->collections->findByHandle('posts');
        self::assertNotNull($collection);
        self::assertFalse($collection->isSingle());
    }

    public function test_an_unknown_kind_is_rejected_and_nothing_is_created(): void
    {
        $schema = $this->tokens->create('S', ['schema:write']);

        // A typo must NOT silently coerce to the publicly-browsable 'collection'.
        $typo = $this->call('create_collection', ['handle' => 'about', 'name' => 'About', 'kind' => 'singleton'], $schema);
        self::assertTrue($typo['result']['isError']);
        self::assertSame('invalid', $typo['result']['structuredContent']['error']['code']);
        self::assertNull($this->collections->findByHandle('about'), 'nothing was created on a bad kind');

        // A non-string kind is rejected too (never a 500).
        $nonString = $this->call('create_collection', ['handle' => 'about', 'name' => 'About', 'kind' => ['single']], $schema);
        self::assertTrue($nonString['result']['isError']);
        self::assertSame('invalid', $nonString['result']['structuredContent']['error']['code']);
    }

    public function test_create_collection_builds_options_server_side_ignoring_over_posted_permissions(): void
    {
        // Over-posting guard: a crafted permissions/options payload must not reach
        // the stored options — they are built server-side from kind alone.
        $schema = $this->tokens->create('S', ['schema:write']);
        $this->call('create_collection', [
            'handle'      => 'home',
            'name'        => 'Home',
            'kind'        => 'single',
            'permissions' => ['manage' => ['admin']],
            'options'     => ['kind' => 'collection', 'permissions' => ['manage' => ['admin']]],
        ], $schema);

        $row = $this->db->selectOne('SELECT options FROM nb_collections WHERE handle = :h', ['h' => 'home']);
        self::assertNotNull($row);
        $stored = json_decode((string) $row['options'], true);
        self::assertSame(['kind' => 'single', 'permissions' => ['manage' => []]], $stored);
    }

    public function test_a_singleton_holds_exactly_one_entry_over_mcp(): void
    {
        $token = $this->tokens->create('T', ['schema:write', '*:read', '*:write']);
        $this->call('create_collection', ['handle' => 'home', 'name' => 'Home', 'kind' => 'single', 'fields' => [$this->field('Tagline', 'text')]], $token);

        $first  = $this->structured($this->call('create_home', ['status' => 'published', 'tagline' => 'First'], $token));
        $second = $this->structured($this->call('create_home', ['status' => 'published', 'tagline' => 'Second'], $token));

        self::assertSame($first['data']['id'], $second['data']['id'], 'a second create targets the same singleton row');
        $row = $this->db->selectOne('SELECT COUNT(*) AS c FROM nb_entries WHERE collection_id = (SELECT id FROM nb_collections WHERE handle = :h)', ['h' => 'home']);
        self::assertNotNull($row);
        self::assertSame(1, (int) $row['c'], 'the singleton has exactly one entry');
    }

    public function test_a_reserved_handle_is_rejected_even_as_a_singleton(): void
    {
        // The reserved-handle guard is options-independent — kind can't smuggle it.
        $schema   = $this->tokens->create('S', ['schema:write']);
        $response = $this->call('create_collection', ['handle' => 'media', 'name' => 'Media', 'kind' => 'single'], $schema);

        self::assertTrue($response['result']['isError']);
        self::assertSame('invalid', $response['result']['structuredContent']['error']['code']);
        self::assertNull($this->collections->findByHandle('media'), 'nothing was created');
    }

    public function test_read_tools_expose_the_collection_kind(): void
    {
        $token = $this->tokens->create('T', ['schema:write', '*:read', '*:write']);
        $this->call('create_collection', ['handle' => 'home', 'name' => 'Home', 'kind' => 'single'], $token);
        $this->call('create_collection', ['handle' => 'posts', 'name' => 'Posts'], $token);

        $list = $this->structured($this->call('list_collections', [], $token))['collections'];
        $kinds = [];
        foreach ($list as $row) {
            $kinds[$row['handle']] = $row['kind'];
        }
        self::assertSame('single', $kinds['home']);
        self::assertSame('collection', $kinds['posts']);

        $described = $this->structured($this->call('describe_collection', ['handle' => 'home'], $token));
        self::assertSame('single', $described['kind']);
    }

    // -------------------------------------------------------- destructive

    public function test_delete_collection_requires_confirmation_and_reports_the_blast_radius(): void
    {
        $schema = $this->tokens->create('S', ['schema:write']);
        $this->call('create_collection', ['handle' => 'temp', 'name' => 'Temp'], $schema);

        // Without confirm → a confirmation_required error carrying the entry count.
        $blocked = $this->call('delete_collection', ['handle' => 'temp'], $schema);
        self::assertTrue($blocked['result']['isError']);
        self::assertSame('confirmation_required', $blocked['result']['structuredContent']['error']['code']);
        self::assertSame('0', $blocked['result']['structuredContent']['error']['fields']['entries']);
        self::assertNotNull($this->collections->findByHandle('temp'), 'still there without confirm');

        // With confirm → gone.
        $done = $this->call('delete_collection', ['handle' => 'temp', 'confirm' => true], $schema);
        self::assertFalse($done['result']['isError']);
        self::assertNull($this->collections->findByHandle('temp'), 'the collection is deleted');
    }

    public function test_delete_collection_refuses_when_a_relation_field_targets_it(): void
    {
        // FU-14 parity: even with confirm, a collection targeted by a relation
        // field elsewhere is refused with an `in_use` error carrying the usage.
        $schema = $this->tokens->create('S', ['schema:write']);
        $this->call('create_collection', ['handle' => 'authors', 'name' => 'Authors'], $schema);
        $this->call('create_collection', ['handle' => 'books', 'name' => 'Books', 'fields' => [
            ['label' => 'Author', 'type' => 'relation', 'options' => ['target' => 'authors']],
        ]], $schema);

        $resp = $this->call('delete_collection', ['handle' => 'authors', 'confirm' => true], $schema);

        self::assertTrue($resp['result']['isError']);
        self::assertSame('in_use', $resp['result']['structuredContent']['error']['code']);
        self::assertNotEmpty($resp['result']['structuredContent']['error']['usage'] ?? [], 'the usage is surfaced');
        self::assertNotNull($this->collections->findByHandle('authors'), 'not deleted');
    }
}

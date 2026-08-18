<?php

declare(strict_types=1);

namespace Nimbus\Tests\Http;

use Nimbus\Api\ApiTokenRepository;
use Nimbus\Content\CollectionRepository;
use Nimbus\Http\Request;
use Nimbus\Http\Response;

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
}

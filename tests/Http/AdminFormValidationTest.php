<?php

declare(strict_types=1);

namespace Nimbus\Tests\Http;

use Nimbus\Api\ApiTokenRepository;
use Nimbus\Http\Request;

/**
 * Admin-form validation hardening (ADMIN-5 / ADMIN-8 / ADMIN-12): over-long,
 * duplicate-handle, or malformed-shape admin input becomes a friendly error, not
 * a 500. Covered on the collections schema form, the management forms, and the
 * MCP schema tools (which share `CollectionService`).
 */
final class AdminFormValidationTest extends HttpTestCase
{
    private function collectionExists(string $handle): bool
    {
        return $this->db->selectOne('SELECT id FROM nb_collections WHERE handle = :h', ['h' => $handle]) !== null;
    }

    // ------------------------------------------------------- schema form (ADMIN-5/12/8)

    public function test_duplicate_field_handles_are_rejected_on_create_with_nothing_saved(): void
    {
        $this->actingAs('admin');
        // "Size!" and "Size?" both normalize to the handle "size".
        $res = $this->post('/admin/collections', [
            'name'   => 'Widgets',
            'fields' => [['label' => 'Size!'], ['label' => 'Size?']],
        ]);

        self::assertSame(200, $res->status, 're-rendered, not 302-success and not 500');
        self::assertStringContainsString('same handle', $res->body, 'the collision is explained on the row');
        self::assertFalse($this->collectionExists('widgets'), 'nothing saved');
    }

    public function test_duplicate_field_handles_are_rejected_on_update(): void
    {
        $posts = $this->makeCollection('posts', [$this->field('size', 'text')]);
        $this->actingAs('admin');

        // Keep the existing field and add another normalizing to the same handle.
        $res = $this->post("/admin/collections/{$posts->id}", [
            'name'   => 'Posts',
            'fields' => [['label' => 'Size', 'handle' => 'size'], ['label' => 'Size!']],
        ]);

        self::assertSame(200, $res->status);
        self::assertStringContainsString('same handle', $res->body);
        self::assertSame(1, (int) $this->db->selectOne('SELECT COUNT(*) AS c FROM nb_fields WHERE collection_id = :c', ['c' => $posts->id])['c'], 'field set unchanged');
    }

    public function test_resubmitting_existing_fields_unchanged_is_not_a_false_positive(): void
    {
        $posts = $this->makeCollection('posts', [$this->field('body', 'textarea')]);
        $this->actingAs('admin');

        $res = $this->post("/admin/collections/{$posts->id}", [
            'name'   => 'Posts',
            'fields' => [['label' => 'Body', 'handle' => 'body']],
        ]);
        $this->assertRedirects($res, '/admin/collections?msg=updated');
    }

    public function test_array_shaped_field_input_does_not_500(): void
    {
        $this->actingAs('admin');
        // Crafted arrays where scalars are expected (ADMIN-12) — no TypeError.
        $res = $this->post('/admin/collections', [
            'name'   => 'C',
            'fields' => [['label' => 'X', 'type' => ['text'], 'handle' => ['x']]],
        ]);
        self::assertNotSame(500, $res->status);
    }

    public function test_over_long_collection_fields_are_rejected(): void
    {
        $this->actingAs('admin');

        // A long NAME derives a >80 handle (the column is VARCHAR(80)); must error, not 500.
        $res = $this->post('/admin/collections', ['name' => str_repeat('a', 200)]);
        self::assertSame(200, $res->status);
        self::assertStringContainsString('Handle must be', $res->body);

        // Over-long description.
        $res = $this->post('/admin/collections', ['name' => 'Ok', 'description' => str_repeat('d', 256)]);
        self::assertSame(200, $res->status);
        self::assertStringContainsString('Description must be', $res->body);
    }

    public function test_an_at_cap_handle_succeeds(): void
    {
        $this->actingAs('admin');
        $handle = str_repeat('a', 80); // exactly nb_collections.handle VARCHAR(80)
        $res    = $this->post('/admin/collections', ['name' => 'Ok', 'handle' => $handle]);
        $this->assertRedirects($res, '/admin/collections?msg=created');
        self::assertTrue($this->collectionExists($handle));
    }

    // ------------------------------------------------------------ management forms (ADMIN-8)

    public function test_management_forms_reject_over_long_input(): void
    {
        $this->actingAs('admin');

        $this->assertRedirectsTo($this->post('/admin/users', ['email' => str_repeat('a', 190) . '@x.test']), '/admin/users?err=');
        $this->assertRedirectsTo($this->post('/admin/users', ['email' => 'ok@x.test', 'name' => str_repeat('n', 121)]), '/admin/users?err=');
        $this->assertRedirectsTo($this->post('/admin/roles', ['name' => str_repeat('r', 81)]), '/admin/roles?err=');
        $this->assertRedirectsTo($this->post('/admin/tokens', ['name' => str_repeat('t', 121)]), '/admin/tokens?err=');
    }

    // ------------------------------------------------------------------- MCP parity

    /**
     * @param array<string,mixed> $args
     * @return array<string,mixed>
     */
    private function mcp(string $tool, array $args, string $token): array
    {
        $server  = ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_AUTHORIZATION' => 'Bearer ' . $token];
        $body    = ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => $args]];
        $request = new Request('POST', '/api/v1/mcp', [], [], $server, [], null, json_encode($body, JSON_THROW_ON_ERROR));
        return json_decode($this->throughKernel($request)->body, true);
    }

    public function test_mcp_create_collection_rejects_duplicate_field_handles_not_500(): void
    {
        $token = (new ApiTokenRepository($this->db))->create('S', ['schema:write']);
        $res   = $this->mcp('create_collection', [
            'handle' => 'widgets', 'name' => 'Widgets',
            'fields' => [['label' => 'Size!'], ['label' => 'Size?']],
        ], $token);

        self::assertSame('invalid', $res['result']['structuredContent']['error']['code'], 'a structured error, not an internal 500');
        self::assertFalse($this->collectionExists('widgets'));
    }

    public function test_mcp_create_collection_rejects_an_over_long_handle(): void
    {
        $token = (new ApiTokenRepository($this->db))->create('S', ['schema:write']);
        $res   = $this->mcp('create_collection', ['handle' => str_repeat('a', 81), 'name' => 'X'], $token);

        self::assertSame('invalid', $res['result']['structuredContent']['error']['code']);
    }

    /**
     * @param array<string,mixed> $options
     * @return array{handle:string,label:string,type:string,required:bool,options:array<string,mixed>}
     */
    private function field(string $handle, string $type, array $options = []): array
    {
        return ['handle' => $handle, 'label' => ucfirst($handle), 'type' => $type, 'required' => false, 'options' => $options];
    }
}

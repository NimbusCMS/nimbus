<?php

declare(strict_types=1);

namespace Nimbus\Tests\Http;

use Nimbus\Api\ApiTokenRepository;
use Nimbus\Http\Request;

/**
 * The structured, AI-friendly validation-error contract: every write surface
 * returns per-field `{code, message}` errors plus a top-level machine `code`.
 * An agent branches on the code; a human reads the message. Locks the shape,
 * the `required` vs `invalid` distinction, the top-level `missing_provider`
 * case, and the security invariants (code is core-assigned; a hostile
 * label/message is safe at every sink).
 */
final class ValidationErrorsTest extends HttpTestCase
{
    private ApiTokenRepository $tokens;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tokens = new ApiTokenRepository($this->db);
    }

    /**
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    private function apiPost(string $handle, array $body, string $token): array
    {
        $server  = ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_AUTHORIZATION' => 'Bearer ' . $token];
        $request = new Request('POST', "/api/v1/collections/{$handle}/entries", [], [], $server, [], null, json_encode($body, JSON_THROW_ON_ERROR));
        $resp    = $this->throughKernel($request);
        return ['status' => $resp->status, 'json' => json_decode($resp->body, true), 'raw' => $resp->body];
    }

    /**
     * @param array<string,mixed> $arguments
     * @return array<string,mixed> the tool result ({ isError, structuredContent, ... })
     */
    private function mcpCall(string $name, array $arguments, string $token): array
    {
        $server  = ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_AUTHORIZATION' => 'Bearer ' . $token];
        $body    = ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $name, 'arguments' => $arguments]];
        $request = new Request('POST', '/api/v1/mcp', [], [], $server, [], null, json_encode($body, JSON_THROW_ON_ERROR));
        return json_decode($this->throughKernel($request)->body, true)['result'];
    }

    // ------------------------------------------------------------------- API

    public function test_api_required_fields_return_structured_errors(): void
    {
        $this->makeCollection('posts', [
            ['handle' => 'body', 'label' => 'Body', 'type' => 'textarea', 'required' => true, 'options' => []],
        ]);
        $token = $this->tokens->create('W', ['posts:write']);

        // Empty title (auto-required) + missing required body.
        $res = $this->apiPost('posts', ['title' => '', 'fields' => []], $token);

        self::assertSame(422, $res['status']);
        self::assertSame('invalid', $res['json']['error']['code']);
        self::assertSame('required', $res['json']['error']['fields']['title']['code']);
        self::assertSame('required', $res['json']['error']['fields']['body']['code']);
        self::assertArrayHasKey('message', $res['json']['error']['fields']['title']);
        self::assertArrayNotHasKey('__title', $res['json']['error']['fields'], 'no pseudo-field keys leak');
    }

    /** A type/format failure is `invalid` — and the code is core-assigned, distinct from the message. */
    public function test_api_type_failure_is_code_invalid(): void
    {
        $this->makeCollection('posts', [
            ['handle' => 'price', 'label' => 'Price', 'type' => 'number', 'required' => false, 'options' => []],
        ]);
        $token = $this->tokens->create('W', ['posts:write']);

        $res = $this->apiPost('posts', ['title' => 'A', 'fields' => ['price' => 'not-a-number']], $token);

        self::assertSame(422, $res['status']);
        self::assertSame('invalid', $res['json']['error']['fields']['price']['code']);
        self::assertNotSame('', $res['json']['error']['fields']['price']['message']);
    }

    public function test_api_missing_provider_is_a_top_level_code(): void
    {
        $c = $this->makeCollection('places');
        $this->db->execute(
            "INSERT INTO nb_fields (collection_id, handle, label, type, required, sort, created_at)
             VALUES (:c, 'where', 'Where', 'geolocation', 0, 0, NOW())",
            ['c' => $c->id],
        );
        $token = $this->tokens->create('W', ['places:write']);

        $res = $this->apiPost('places', ['title' => 'Trafalgar', 'fields' => []], $token);

        self::assertSame(422, $res['status']);
        self::assertSame('missing_provider', $res['json']['error']['code']);
        self::assertStringContainsString('geolocation', $res['json']['error']['message']);
        self::assertSame([], (array) $res['json']['error']['fields'], 'no per-field entries for a provider failure');
    }

    /** A3 — a hostile field label flows into a message but is JSON-safe (quote escaped, round-trips as data). */
    public function test_api_hostile_label_is_safe_in_json(): void
    {
        $this->makeCollection('posts', [
            ['handle' => 'body', 'label' => '"><script>alert(1)</script>', 'type' => 'textarea', 'required' => true, 'options' => []],
        ]);
        $token = $this->tokens->create('W', ['posts:write']);

        $res = $this->apiPost('posts', ['title' => 'A', 'fields' => []], $token);

        self::assertSame('required', $res['json']['error']['fields']['body']['code']);
        // The quote is escaped in the wire bytes, so it cannot break the JSON string...
        self::assertStringContainsString('\\"><script>', $res['raw']);
        // ...and the value decodes back intact as data (served application/json, never HTML).
        self::assertStringContainsString('<script>alert(1)</script>', $res['json']['error']['fields']['body']['message']);
    }

    /** A3 (the XSS-relevant sink) — the admin renders a hostile label-in-message HTML-escaped. */
    public function test_admin_escapes_a_hostile_label_in_the_error(): void
    {
        $this->makeCollection('posts', [
            ['handle' => 'body', 'label' => '<script>alert(1)</script>', 'type' => 'textarea', 'required' => true, 'options' => []],
        ]);
        $this->actingAs('admin');

        $body = $this->post('/admin/collections/posts/entries', ['title' => 'A', 'status' => 'draft'])->body;

        self::assertStringContainsString('&lt;script&gt;', $body);
        self::assertStringNotContainsString('<script>alert(1)</script>', $body);
    }

    // ------------------------------------------------------------------- MCP

    public function test_mcp_validation_errors_are_structured(): void
    {
        $this->makeCollection('posts', [
            ['handle' => 'body', 'label' => 'Body', 'type' => 'textarea', 'required' => true, 'options' => []],
        ]);
        $token = $this->tokens->create('W', ['posts:write']);

        $res = $this->mcpCall('create_posts', ['title' => '', 'fields' => []], $token);

        self::assertTrue($res['isError']);
        $error = $res['structuredContent']['error'];
        self::assertSame('invalid', $error['code']);
        self::assertSame('required', $error['fields']['title']['code']);
        self::assertSame('required', $error['fields']['body']['code']);
    }

    // ----------------------------------------------------------------- admin

    public function test_admin_shows_prose_and_normalized_title_error(): void
    {
        $this->makeCollection('posts');
        $this->actingAs('admin');

        $body = $this->post('/admin/collections/posts/entries', ['title' => '', 'status' => 'draft'])->body;

        self::assertStringContainsString('Title is required.', $body);
        self::assertStringContainsString('Please fix the highlighted fields.', $body);
    }

    public function test_admin_missing_provider_shows_the_top_level_alert(): void
    {
        $c = $this->makeCollection('places');
        $this->db->execute(
            "INSERT INTO nb_fields (collection_id, handle, label, type, required, sort, created_at)
             VALUES (:c, 'where', 'Where', 'geolocation', 0, 0, NOW())",
            ['c' => $c->id],
        );
        $this->actingAs('admin');

        $body = $this->post('/admin/collections/places/entries', ['title' => 'X', 'status' => 'draft'])->body;

        self::assertStringContainsString('geolocation', $body);
        self::assertStringContainsString('unavailable', $body);
    }
}

<?php

declare(strict_types=1);

namespace Nimbus\Tests\Http;

use Nimbus\Api\ApiTokenRepository;
use Nimbus\Content\EntryInput;
use Nimbus\Content\EntryRepository;
use Nimbus\Content\EntryService;
use Nimbus\Content\FieldTypeRegistry;
use Nimbus\Content\RelationRepository;
use Nimbus\Http\Request;
use Nimbus\Media\MediaRepository;
use Nimbus\Support\EventDispatcher;

/**
 * MCP media tools (ADR 0009, Slice 5b): the library over JSON-RPC, gated on
 * media:read / media:write. Proves the capability gate, that upload verifies the
 * type from the real bytes (base64 in), and that delete goes through the shared
 * guard — blocked + pinpointed when the file is still in use.
 */
final class McpMediaToolsTest extends HttpTestCase
{
    // A real 1x1 PNG — finfo must sniff this as image/png.
    private const PNG_1x1 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    private ApiTokenRepository $tokens;
    private MediaRepository $media;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tokens = new ApiTokenRepository($this->db);
        $this->media  = new MediaRepository($this->db);
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
        return array_column(json_decode($this->throughKernel($request)->body, true)['result']['tools'], 'name');
    }

    /**
     * @param array<string,mixed> $response
     * @return array<string,mixed>
     */
    private function structured(array $response): array
    {
        return $response['result']['structuredContent'];
    }

    // ---------------------------------------------------------------- gating

    public function test_media_tools_are_capability_gated(): void
    {
        $content = $this->tokens->create('C', ['posts:read']);
        $read    = $this->tokens->create('R', ['media:read']);
        $write   = $this->tokens->create('W', ['media:read', 'media:write']);

        self::assertNotContains('upload_media', $this->toolNames($content));
        self::assertSame(-32602, $this->call('upload_media', ['filename' => 'x.png', 'data' => self::PNG_1x1], $content)['error']['code']);

        $readTools = $this->toolNames($read);
        self::assertContains('list_media', $readTools);
        self::assertContains('media_usage', $readTools);
        self::assertNotContains('upload_media', $readTools, 'read alone cannot upload');

        self::assertContains('upload_media', $this->toolNames($write));
        self::assertContains('delete_media', $this->toolNames($write));
    }

    // --------------------------------------------------------------- upload

    public function test_upload_then_get_and_list(): void
    {
        $token = $this->tokens->create('W', ['media:read', 'media:write']);

        $up = $this->structured($this->call('upload_media', ['filename' => 'pixel.png', 'data' => self::PNG_1x1, 'alt' => 'a dot'], $token));
        $id = $up['data']['id'];
        self::assertSame('image/png', $up['data']['mime'], 'the type is sniffed from the bytes');
        self::assertSame('a dot', $up['data']['alt']);

        self::assertSame($id, $this->structured($this->call('get_media', ['id' => $id], $token))['data']['id']);
        $listed = array_column($this->structured($this->call('list_media', [], $token))['data'], 'id');
        self::assertContains($id, $listed);
    }

    public function test_upload_rejects_a_non_image_by_sniffing_the_bytes(): void
    {
        $token    = $this->tokens->create('W', ['media:write']);
        $response = $this->call('upload_media', ['filename' => 'evil.png', 'data' => base64_encode('this is plain text, not a PNG')], $token);

        self::assertTrue($response['result']['isError']);
        self::assertSame('invalid', $response['result']['structuredContent']['error']['code'], 'a fake .png is rejected on content');
    }

    // -------------------------------------------------------- delete + guard

    public function test_delete_media_when_unused(): void
    {
        $token = $this->tokens->create('W', ['media:read', 'media:write']);
        $id    = $this->structured($this->call('upload_media', ['filename' => 'gone.png', 'data' => self::PNG_1x1], $token))['data']['id'];

        self::assertFalse($this->call('delete_media', ['id' => $id], $token)['result']['isError']);
        self::assertTrue($this->call('get_media', ['id' => $id], $token)['result']['isError'], 'the item is gone');
    }

    public function test_delete_media_is_blocked_when_in_use_and_pinpoints_it(): void
    {
        $token = $this->tokens->create('W', ['media:read', 'media:write']);
        $id    = $this->structured($this->call('upload_media', ['filename' => 'held.png', 'data' => self::PNG_1x1], $token))['data']['id'];

        // Reference the media from an entry (via the same service the admin uses).
        $gallery = $this->makeCollection('gallery', [['handle' => 'photo', 'label' => 'Photo', 'type' => 'media', 'required' => false, 'options' => []]]);
        $service = new EntryService($this->db, new EntryRepository($this->db), new RelationRepository($this->db), new FieldTypeRegistry(), new EventDispatcher());
        $service->save($gallery, new EntryInput('Shot', 'shot', 'published', ['photo' => $id]), null, null);

        // The delete is refused and says where.
        $blocked = $this->call('delete_media', ['id' => $id], $token);
        self::assertTrue($blocked['result']['isError']);
        self::assertSame('in_use', $blocked['result']['structuredContent']['error']['code']);
        self::assertSame('gallery', $blocked['result']['structuredContent']['error']['usage'][0]['collection']);
        self::assertNotNull($this->media->find($id), 'the file is untouched while in use');

        // media_usage reports the same, so an agent can check before deleting.
        $usage = $this->structured($this->call('media_usage', ['id' => $id], $token));
        self::assertSame('photo', $usage['usage'][0]['field_handle']);
    }
}

<?php

declare(strict_types=1);

namespace Nimbus\Tests\Integration;

use Nimbus\Api\ApiTokenRepository;
use Nimbus\Api\EntryOpContext;
use Nimbus\Api\EntryOperations;
use Nimbus\Api\TokenPrincipal;
use Nimbus\Content\CollectionRepository;
use Nimbus\Content\FieldTypeRegistry;
use Nimbus\Mcp\ContentToolset;
use Nimbus\Mcp\Guide\GuideLibrary;
use Nimbus\Mcp\McpServer;
use Nimbus\Mcp\StdioTransport;
use Nimbus\Support\EventDispatcher;

/**
 * The MCP stdio transport (ADR 0009): the same server framed over stdin/stdout
 * as newline-delimited JSON-RPC. Proves the framing contract — one reply line
 * per request, a notification produces no line, a malformed line becomes a
 * parse/invalid error, and end-of-input stops cleanly — while the protocol
 * itself is the shared McpServer (covered end-to-end by McpTest).
 */
final class StdioTransportTest extends IntegrationTestCase
{
    /**
     * Feed the given raw lines through a real transport and return the decoded
     * response objects it wrote (one per output line).
     *
     * @param list<string> $lines
     * @return list<array<string,mixed>>
     */
    private function exchange(array $lines, string $scopes = 'posts:read,posts:write'): array
    {
        $token     = (new ApiTokenRepository($this->db))->create('stdio', explode(',', $scopes));
        $resolved  = (new ApiTokenRepository($this->db))->findByPlaintext($token);
        self::assertNotNull($resolved);

        $types  = new FieldTypeRegistry();
        $events = new EventDispatcher();
        $server = new McpServer(new GuideLibrary('Test instructions.'), 'test', new ContentToolset(
            new CollectionRepository($this->db),
            $types,
            new EntryOperations($this->db, $types, $events),
        ));
        $transport = new StdioTransport($server, TokenPrincipal::fromToken($resolved), new EntryOpContext('stdio', 'mcp'));

        $in  = $this->stream(implode("\n", $lines) . "\n");
        $out = fopen('php://memory', 'r+');
        self::assertIsResource($out);

        $transport->run($in, $out);

        rewind($out);
        $raw = stream_get_contents($out);
        self::assertIsString($raw);

        $decoded = [];
        foreach (explode("\n", trim($raw)) as $line) {
            if ($line !== '') {
                $decoded[] = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            }
        }
        return $decoded;
    }

    /** @return resource */
    private function stream(string $contents)
    {
        $s = fopen('php://memory', 'r+');
        self::assertIsResource($s);
        fwrite($s, $contents);
        rewind($s);
        return $s;
    }

    public function test_each_request_gets_one_reply_line_in_order(): void
    {
        $replies = $this->exchange([
            '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{}}',
            '{"jsonrpc":"2.0","id":2,"method":"tools/list","params":{}}',
        ]);

        self::assertCount(2, $replies, 'two requests → two reply lines');
        self::assertSame(1, $replies[0]['id']);
        self::assertSame('NimbusCMS', $replies[0]['result']['serverInfo']['name']);
        self::assertSame(2, $replies[1]['id']);
        self::assertArrayHasKey('tools', $replies[1]['result']);
    }

    public function test_a_notification_produces_no_line(): void
    {
        $replies = $this->exchange([
            '{"jsonrpc":"2.0","method":"notifications/initialized"}',
            '{"jsonrpc":"2.0","id":7,"method":"ping","params":{}}',
        ]);

        self::assertCount(1, $replies, 'the notification is silent; only ping replies');
        self::assertSame(7, $replies[0]['id']);
    }

    public function test_a_malformed_line_becomes_a_parse_error(): void
    {
        $replies = $this->exchange(['{ this is not json ']);

        self::assertCount(1, $replies);
        self::assertNull($replies[0]['id']);
        self::assertSame(-32700, $replies[0]['error']['code']);
    }

    public function test_a_non_object_line_is_an_invalid_request(): void
    {
        $replies = $this->exchange(['[1, 2, 3]']);

        self::assertSame(-32600, $replies[0]['error']['code']);
    }

    public function test_blank_lines_are_skipped_and_eof_stops_cleanly(): void
    {
        $replies = $this->exchange([
            '',
            '{"jsonrpc":"2.0","id":1,"method":"ping","params":{}}',
            '',
        ]);

        self::assertCount(1, $replies);
        self::assertSame(1, $replies[0]['id']);
    }
}

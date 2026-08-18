<?php

declare(strict_types=1);

namespace Nimbus\Mcp;

use JsonException;
use Nimbus\Api\EntryOpContext;
use Nimbus\Api\TokenPrincipal;

/**
 * The MCP stdio transport (ADR 0009) — the local desktop-client front door.
 *
 * It frames the *same* {@see McpServer} over stdin/stdout as newline-delimited
 * JSON-RPC: one message per line in, one response line out, notifications get no
 * line, and a malformed line becomes a parse/invalid-request error. It adds no
 * protocol logic — that all lives in the server, shared with the HTTP transport,
 * so the two never diverge.
 *
 * Auth is unchanged in spirit: `nimbus mcp` resolves a **scoped token** (from the
 * environment) to the {@see TokenPrincipal} handed in here, so even locally the
 * session is capability-scoped, never raw database access. Anything the server
 * needs to log must go to stderr — stdout carries only the JSON-RPC stream.
 */
final class StdioTransport
{
    public function __construct(
        private McpServer $server,
        private TokenPrincipal $principal,
        private EntryOpContext $context,
    ) {
    }

    /**
     * Read messages until end-of-input, replying to each on its own line.
     *
     * @param resource $in  the input stream (STDIN)
     * @param resource $out the output stream (STDOUT)
     */
    public function run($in, $out): void
    {
        while (($line = fgets($in)) !== false) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $reply = $this->handleLine($line);
            if ($reply !== null) {
                fwrite($out, json_encode($reply, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
                fflush($out);
            }
        }
    }

    /**
     * Turn one input line into a response envelope, or null for a notification.
     *
     * @return array<string,mixed>|null
     */
    private function handleLine(string $line): ?array
    {
        try {
            $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return JsonRpc::error(null, JsonRpc::PARSE_ERROR, 'Parse error: the line is not valid JSON.');
        }
        // A JSON-RPC message must be an object. json_decode(assoc) turns both a
        // JSON object and a JSON array into a PHP array, so a non-empty list (or
        // a scalar) is rejected here rather than mistaken for a notification.
        if (!is_array($decoded) || (array_is_list($decoded) && $decoded !== [])) {
            return JsonRpc::error(null, JsonRpc::INVALID_REQUEST, 'A JSON-RPC message must be an object.');
        }

        return $this->server->handle($decoded, $this->principal, $this->context);
    }
}

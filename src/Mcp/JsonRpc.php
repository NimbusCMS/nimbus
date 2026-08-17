<?php

declare(strict_types=1);

namespace Nimbus\Mcp;

/**
 * The JSON-RPC 2.0 envelope MCP speaks over (ADR 0009).
 *
 * A tiny, transport-agnostic helper: it builds result and error envelopes and
 * owns the standard error codes, so both the HTTP transport (`POST /api/v1/mcp`)
 * and the coming stdio transport (`nimbus mcp`) frame messages identically.
 */
final class JsonRpc
{
    public const PARSE_ERROR      = -32700;
    public const INVALID_REQUEST  = -32600;
    public const METHOD_NOT_FOUND = -32601;
    public const INVALID_PARAMS   = -32602;
    public const INTERNAL_ERROR   = -32603;

    /**
     * A success envelope for request $id.
     *
     * @param array<string,mixed> $result
     * @return array<string,mixed>
     */
    public static function result(string|int|null $id, array $result): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    /**
     * An error envelope for request $id.
     *
     * @return array<string,mixed>
     */
    public static function error(string|int|null $id, int $code, string $message): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
    }
}

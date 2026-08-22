<?php

declare(strict_types=1);

namespace Nimbus\Mcp;

/**
 * Builders for an MCP `tools/call` result, shared by every toolset so a success
 * and a tool-level failure look identical across the content and management
 * surfaces. A tool *result* — never a JSON-RPC protocol error (those are thrown
 * as {@see McpError}); an operation that failed is a normal result with
 * `isError: true` so the calling agent can read and react to it.
 */
final class ToolResult
{
    /**
     * A successful result: the structured payload, plus a text mirror for
     * clients that only render `content`.
     *
     * @param array<string,mixed> $structured
     * @return array<string,mixed>
     */
    public static function ok(array $structured): array
    {
        return [
            'content'           => [['type' => 'text', 'text' => self::encode($structured)]],
            'structuredContent' => $structured,
            'isError'           => false,
        ];
    }

    /**
     * A tool-level error: a code, a message, optional field errors, and optional
     * structured extras (e.g. where a file is still used) merged into the error.
     *
     * @param array<string,mixed> $fields per-field errors — either a plain map or `{code, message}` objects
     * @param array<string,mixed>  $extra
     * @return array<string,mixed>
     */
    public static function error(string $message, string $code, array $fields = [], array $extra = []): array
    {
        $error = ['code' => $code, 'message' => $message];
        if ($fields !== []) {
            $error['fields'] = $fields;
        }
        foreach ($extra as $key => $value) {
            $error[$key] = $value;
        }
        $structured = ['error' => $error];
        return [
            'content'           => [['type' => 'text', 'text' => self::encode($structured)]],
            'structuredContent' => $structured,
            'isError'           => true,
        ];
    }

    /** @param array<string,mixed> $value */
    private static function encode(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
}

<?php

declare(strict_types=1);

namespace Nimbus\Mcp;

use RuntimeException;

/**
 * A JSON-RPC *protocol* error — a malformed request, an unknown method, an
 * unknown tool, bad params. It carries a JSON-RPC error code and is caught at
 * the server boundary and turned into an error envelope.
 *
 * Note the deliberate split (MCP spec): a *protocol* error means the call itself
 * was invalid, and is thrown here; a *tool execution* failure (validation,
 * concurrency, a forbidden action) is not an exception — it is a normal tool
 * result with `isError: true`, so the calling agent sees it and can react.
 */
final class McpError extends RuntimeException
{
    public function __construct(
        public readonly int $rpcCode,
        string $message,
    ) {
        parent::__construct($message);
    }
}

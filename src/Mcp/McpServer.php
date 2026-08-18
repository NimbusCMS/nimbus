<?php

declare(strict_types=1);

namespace Nimbus\Mcp;

use Nimbus\Api\EntryOpContext;
use Nimbus\Api\TokenPrincipal;

/**
 * The MCP server core (ADR 0009) — transport-agnostic JSON-RPC 2.0 dispatch.
 *
 * It takes one decoded JSON-RPC message, an authenticated {@see TokenPrincipal}
 * and an audit context, and returns the decoded response (or null for a
 * notification, which gets no reply). It knows nothing about HTTP or stdio: the
 * HTTP transport (`POST /api/v1/mcp`) and the coming stdio transport
 * (`nimbus mcp`) both frame bytes and hand a decoded message here, so the two
 * transports share one protocol implementation.
 *
 * v1 implements just what a tool client needs — `initialize`, `tools/list`,
 * `tools/call`, `ping` — plus the `notifications/*` a client emits. MCP
 * resources / prompts / sampling are out of scope until a concrete need appears.
 */
final class McpServer
{
    /** The MCP protocol revision this server implements. */
    public const PROTOCOL_VERSION = '2025-06-18';

    private const SERVER_NAME = 'NimbusCMS';
    // Metadata only; the release process should source this from the CMS version.
    private const SERVER_VERSION = '0.1.0-alpha';

    /** @var list<Toolset> */
    private array $toolsets;

    /**
     * @param Toolset ...$toolsets the tool groups, in priority order — management
     *   toolsets first, content last, so a fixed management name (e.g.
     *   `create_collection`) is claimed before a content verb could parse it.
     */
    public function __construct(Toolset ...$toolsets)
    {
        $this->toolsets = array_values($toolsets);
    }

    /**
     * Handle one JSON-RPC message. Returns the response envelope, or null for a
     * notification (a message with no `id` — no reply is sent).
     *
     * @param array<string,mixed> $message the decoded request
     * @return array<string,mixed>|null
     */
    public function handle(array $message, TokenPrincipal $principal, EntryOpContext $ctx): ?array
    {
        $isNotification = !array_key_exists('id', $message);
        $id             = $this->id($message);

        if (($message['jsonrpc'] ?? null) !== '2.0' || !is_string($message['method'] ?? null)) {
            return $isNotification ? null : JsonRpc::error($id, JsonRpc::INVALID_REQUEST, 'Not a valid JSON-RPC 2.0 request.');
        }

        // A notification (including notifications/initialized, notifications/*)
        // is fire-and-forget: do the work if we have it, but never reply.
        if ($isNotification) {
            return null;
        }

        $method = $message['method'];
        $params = is_array($message['params'] ?? null) ? $message['params'] : [];

        try {
            $result = match ($method) {
                'initialize' => $this->initialize(),
                'ping'       => [],
                'tools/list' => ['tools' => $this->allDefinitions($principal)],
                'tools/call' => $this->callTool($params, $principal, $ctx),
                default      => throw new McpError(JsonRpc::METHOD_NOT_FOUND, "Unknown method \"{$method}\"."),
            };
        } catch (McpError $e) {
            return JsonRpc::error($id, $e->rpcCode, $e->getMessage());
        }

        return JsonRpc::result($id, $result);
    }

    /**
     * The union of every toolset's scope-filtered definitions.
     *
     * @return list<array<string,mixed>>
     */
    private function allDefinitions(TokenPrincipal $principal): array
    {
        $tools = [];
        foreach ($this->toolsets as $toolset) {
            foreach ($toolset->definitions($principal) as $tool) {
                $tools[] = $tool;
            }
        }
        return $tools;
    }

    /**
     * Dispatch a `tools/call` to the first toolset that owns the name; if none
     * do, it's an unknown tool. A toolset that owns the name but denies it on
     * scope raises the unknown-tool error itself (non-enumeration).
     *
     * @param array<string,mixed> $params the JSON-RPC params: `name` + `arguments`
     * @return array<string,mixed>
     */
    private function callTool(array $params, TokenPrincipal $principal, EntryOpContext $ctx): array
    {
        $name = is_string($params['name'] ?? null) ? $params['name'] : '';
        $args = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];
        if ($name === '') {
            throw new McpError(JsonRpc::INVALID_PARAMS, 'A tool call needs a "name".');
        }

        foreach ($this->toolsets as $toolset) {
            $result = $toolset->call($name, $args, $principal, $ctx);
            if ($result !== null) {
                return $result;
            }
        }
        throw new McpError(JsonRpc::INVALID_PARAMS, "Unknown tool \"{$name}\".");
    }

    /** @return array<string,mixed> */
    private function initialize(): array
    {
        return [
            'protocolVersion' => self::PROTOCOL_VERSION,
            'serverInfo'      => ['name' => self::SERVER_NAME, 'version' => self::SERVER_VERSION],
            'capabilities'    => ['tools' => ['listChanged' => false]],
        ];
    }

    /**
     * The request id, normalized. JSON-RPC ids are a string, a number, or null;
     * anything else is coerced away so a malformed id cannot ride into the reply.
     */
    private function id(mixed $message): string|int|null
    {
        $id = is_array($message) ? ($message['id'] ?? null) : null;
        return is_string($id) || is_int($id) ? $id : null;
    }
}

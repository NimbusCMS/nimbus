<?php

declare(strict_types=1);

namespace Nimbus\Api;

use Nimbus\Http\Response;

/**
 * The API's response shapes, kept in one place so every endpoint and every
 * error look the same.
 *
 *   success: { "data": ..., "meta": { ... } }   (meta omitted for a single item)
 *   error:   { "error": { "status": 404, "code": "not_found", "message": "..." } }
 *
 * Every error carries a stable, machine-readable `code` alongside the human
 * `message`: a client branches on the code, never on the prose. The codes are
 * part of the public API contract (see docs/COMPATIBILITY.md):
 *
 *   unauthorized (401) · forbidden (403) · not_found (404) · rate_limited (429)
 */
final class ApiResponse
{
    /**
     * @param array<string,mixed>|list<mixed> $data
     * @param array<string,mixed>|null $meta
     */
    public static function ok(array $data, ?array $meta = null): Response
    {
        $body = ['data' => $data];
        if ($meta !== null) {
            $body['meta'] = $meta;
        }
        return Response::json($body);
    }

    /** The one place an API error is shaped; every helper below routes through it. */
    public static function error(int $status, string $code, string $message): Response
    {
        return Response::json(['error' => ['status' => $status, 'code' => $code, 'message' => $message]], $status);
    }

    public static function unauthorized(string $message = 'A valid API token is required.'): Response
    {
        return self::error(401, 'unauthorized', $message)->withHeader('WWW-Authenticate', 'Bearer');
    }

    public static function forbidden(string $message = 'This token is not allowed to do that.'): Response
    {
        return self::error(403, 'forbidden', $message);
    }

    public static function notFound(string $message = 'Not found.'): Response
    {
        return self::error(404, 'not_found', $message);
    }
}

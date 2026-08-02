<?php

declare(strict_types=1);

namespace Nimbus\Api;

use Nimbus\Http\Response;

/**
 * The API's response shapes, kept in one place so every endpoint and every
 * error look the same.
 *
 *   success: { "data": ..., "meta": { ... } }   (meta omitted for a single item)
 *   error:   { "error": { "status": 404, "message": "..." } }
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

    public static function error(int $status, string $message): Response
    {
        return Response::json(['error' => ['status' => $status, 'message' => $message]], $status);
    }

    public static function unauthorized(string $message = 'A valid API token is required.'): Response
    {
        return self::error(401, $message)->withHeader('WWW-Authenticate', 'Bearer');
    }

    public static function notFound(string $message = 'Not found.'): Response
    {
        return self::error(404, $message);
    }
}

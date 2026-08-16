<?php

declare(strict_types=1);

namespace Nimbus\Http;

use Nimbus\Support\Config;

/**
 * Minimal CORS for the read API, driven by an allow-list of origins
 * (CORS_ALLOWED_ORIGINS, comma-separated; `*` allows any). Off by default —
 * an empty list means same-origin only, and no `Access-Control-*` headers.
 *
 * Two responsibilities, because the middleware pipeline only pre-processes:
 * `preflight()` answers a browser's `OPTIONS` probe *before* auth (a preflight
 * carries no token), and `decorate()` annotates an actual response — both only
 * when the request's Origin is on the allow-list. The allowed origin is echoed
 * verbatim (with `Vary: Origin`) and can only ever be a value we approved, so a
 * crafted Origin cannot widen access. The API authenticates by bearer token, not
 * cookies, so no `Allow-Credentials` is emitted.
 */
final class Cors
{
    private const API_PREFIX = '/api/';

    public static function isApiPreflight(Request $request): bool
    {
        return $request->method === 'OPTIONS'
            && str_starts_with($request->path, self::API_PREFIX)
            && $request->header('Origin') !== null;
    }

    public static function preflight(Request $request): Response
    {
        $response = Response::file('', 'text/plain; charset=UTF-8', 204)
            ->withHeader('Access-Control-Allow-Methods', 'GET, OPTIONS')
            ->withHeader('Access-Control-Allow-Headers', 'Authorization, Content-Type')
            ->withHeader('Access-Control-Max-Age', '600');

        return self::allow($response, $request);
    }

    /** Annotate an actual API response with the CORS headers, when the Origin is allowed. */
    public static function decorate(Response $response, Request $request): Response
    {
        if (!str_starts_with($request->path, self::API_PREFIX) || $request->header('Origin') === null) {
            return $response;
        }

        return self::allow($response, $request);
    }

    private static function allow(Response $response, Request $request): Response
    {
        $origin = (string) $request->header('Origin');
        if (!self::originAllowed($origin)) {
            return $response;
        }

        return $response
            ->withHeader('Access-Control-Allow-Origin', $origin)
            ->withHeader('Vary', 'Origin');
    }

    private static function originAllowed(string $origin): bool
    {
        $allowed = self::origins();

        return in_array('*', $allowed, true) || in_array($origin, $allowed, true);
    }

    /** @return string[] */
    private static function origins(): array
    {
        $raw = trim(Config::corsOrigins());
        if ($raw === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $raw)), static fn (string $o): bool => $o !== ''));
    }
}

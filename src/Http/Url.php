<?php

declare(strict_types=1);

namespace Nimbus\Http;

/**
 * Request-scoped URL generation from named routes, so controllers build
 * redirects from a route's *name* rather than a hardcoded path — change a path
 * once (at its `->name()`d definition) and every redirect follows.
 *
 * The kernel {@see \Nimbus\Application} calls {@see bind()} with the fully-built
 * Router before dispatch; controllers then call {@see to()}. Query strings are
 * not part of a route, so callers append `?…` themselves. Like {@see Csp}, this
 * is a request-scoped binding (rebound each request), not application state.
 */
final class Url
{
    private static ?Router $router = null;

    public static function bind(Router $router): void
    {
        self::$router = $router;
    }

    /**
     * The path for a named route. Throws if no router is bound or the name is
     * unknown — a broken redirect target fails loudly, never silently 404s.
     *
     * @param array<string,mixed> $params
     */
    public static function to(string $name, array $params = []): string
    {
        if (self::$router === null) {
            throw new \RuntimeException('Url::to() called before a router was bound.');
        }
        return self::$router->url($name, $params);
    }
}

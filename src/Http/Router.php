<?php

declare(strict_types=1);

namespace Nimbus\Http;

/**
 * A tiny regex router. Patterns use {name} placeholders, e.g.
 * "/admin/collections/{handle}/entries/{id}". The first matching route wins;
 * its handler receives an array of captured params.
 */
final class Router
{
    /** @var array<int,array{0:string,1:string,2:callable}> */
    private array $routes = [];

    public function get(string $pattern, callable $handler): void
    {
        $this->routes[] = ['GET', $pattern, $handler];
    }

    public function post(string $pattern, callable $handler): void
    {
        $this->routes[] = ['POST', $pattern, $handler];
    }

    /**
     * Dispatch; returns the handler result, or null when nothing matched.
     */
    public function dispatch(string $method, string $path): mixed
    {
        foreach ($this->routes as [$routeMethod, $pattern, $handler]) {
            if ($routeMethod !== $method) {
                continue;
            }
            if (preg_match($this->toRegex($pattern), $path, $matches)) {
                $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
                return ['matched' => true, 'result' => $handler($params)];
            }
        }
        return null;
    }

    private function toRegex(string $pattern): string
    {
        $regex = preg_replace('#\{(\w+)\}#', '(?P<$1>[^/]+)', $pattern);
        return '#^' . $regex . '/?$#';
    }
}

<?php

declare(strict_types=1);

namespace Nimbus\Http;

/**
 * A small, explicit router. Routes use `{name}` placeholders; the first match
 * wins. Supports named routes (for URL generation) and middleware groups
 * (a shared prefix + middleware applied to routes registered inside).
 */
final class Router
{
    /** @var Route[] */
    private array $routes = [];

    /** @var string[] active group-prefix stack */
    private array $prefixes = [];

    /** @var array<int,array<int,callable>> active group-middleware stack */
    private array $middlewareStack = [];

    /** @param callable(Request,array<string,string>):Response $handler */
    public function get(string $pattern, callable $handler): Route
    {
        return $this->add('GET', $pattern, $handler);
    }

    /** @param callable(Request,array<string,string>):Response $handler */
    public function post(string $pattern, callable $handler): Route
    {
        return $this->add('POST', $pattern, $handler);
    }

    /**
     * Register routes under a shared path prefix + middleware.
     *
     * @param array<int,callable> $middleware
     */
    public function group(string $prefix, array $middleware, callable $register): void
    {
        $this->prefixes[]        = $prefix;
        $this->middlewareStack[] = $middleware;
        $register($this);
        array_pop($this->prefixes);
        array_pop($this->middlewareStack);
    }

    /** Dispatch a request. Returns the Response, or null when nothing matched. */
    public function dispatch(Request $request): ?Response
    {
        foreach ($this->routes as $route) {
            if ($route->method !== $request->method) {
                continue;
            }
            $params = $route->match($request->path);
            if ($params !== null) {
                return $route->run($request, $params);
            }
        }
        return null;
    }

    /**
     * Every registered route, in match order. Read-only introspection — used
     * by tests to assert the handler contract, and by future route listings.
     *
     * @return Route[]
     */
    public function routes(): array
    {
        return $this->routes;
    }

    /**
     * Generate a URL for a named route.
     *
     * @param array<string,mixed> $params
     */
    public function url(string $name, array $params = []): string
    {
        foreach ($this->routes as $route) {
            if ($route->routeName() === $name) {
                return $route->url($params);
            }
        }
        throw new \RuntimeException("Unknown route name: {$name}");
    }

    /** @param callable(Request,array<string,string>):Response $handler */
    private function add(string $method, string $pattern, callable $handler): Route
    {
        $route = new Route(
            $method,
            implode('', $this->prefixes) . $pattern,
            $handler,
            $this->middlewareStack === [] ? [] : array_merge(...$this->middlewareStack),
        );
        $this->routes[] = $route;
        return $route;
    }
}

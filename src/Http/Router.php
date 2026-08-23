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

    /** @param callable(Request,array<string,string>):Response $handler */
    public function patch(string $pattern, callable $handler): Route
    {
        return $this->add('PATCH', $pattern, $handler);
    }

    /** @param callable(Request,array<string,string>):Response $handler */
    public function put(string $pattern, callable $handler): Route
    {
        return $this->add('PUT', $pattern, $handler);
    }

    /** @param callable(Request,array<string,string>):Response $handler */
    public function delete(string $pattern, callable $handler): Route
    {
        return $this->add('DELETE', $pattern, $handler);
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

    /**
     * Dispatch a request. Returns the matched route's Response; a 405 (with an
     * `Allow` header) when the path matches a route but not its method; or null
     * when nothing matched at all (→ the kernel's 404).
     *
     * HEAD is served by the GET route (RFC 9110 §9.3.2) — the kernel strips the
     * body afterwards. `Allow` lists the methods that do match the path, plus HEAD
     * wherever GET is offered.
     */
    public function dispatch(Request $request): ?Response
    {
        $effectiveMethod = $request->method === 'HEAD' ? 'GET' : $request->method;

        /** @var array<string,true> $allowed methods whose route matched the path */
        $allowed = [];
        foreach ($this->routes as $route) {
            $params = $route->match($request->path);
            if ($params === null) {
                continue;
            }
            if ($route->method === $effectiveMethod) {
                return $route->run($request, $params);
            }
            $allowed[$route->method] = true;
        }

        if ($allowed === []) {
            return null; // no route matches this path → 404
        }

        // The path exists but not for this method → 405. HEAD is served wherever
        // GET is, so advertise it too.
        $methods = array_keys($allowed);
        if (isset($allowed['GET']) && !isset($allowed['HEAD'])) {
            $methods[] = 'HEAD';
        }

        return Response::file('Method not allowed', 'text/plain; charset=UTF-8', 405)
            ->withHeader('Allow', implode(', ', $methods));
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

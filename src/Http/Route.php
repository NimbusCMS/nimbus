<?php

declare(strict_types=1);

namespace Nimbus\Http;

/**
 * A single route: method + pattern + handler, plus an optional name (for URL
 * generation) and a middleware stack. `{param}` placeholders are captured and
 * passed to the handler; the same pattern generates URLs via url().
 */
final class Route
{
    private ?string $name = null;

    /** @var array<int,callable> middleware run before the handler; return a Response to short-circuit */
    private array $middleware;

    /**
     * @param callable        $handler
     * @param array<int,callable> $middleware
     */
    public function __construct(
        public readonly string $method,
        public readonly string $pattern,
        private $handler,
        array $middleware = [],
    ) {
        $this->middleware = $middleware;
    }

    public function name(string $name): self
    {
        $this->name = $name;
        return $this;
    }

    public function routeName(): ?string
    {
        return $this->name;
    }

    public function middleware(callable ...$middleware): self
    {
        $this->middleware = array_merge($this->middleware, $middleware);
        return $this;
    }

    /**
     * Run middleware then the handler for a matched request.
     *
     * @param array<string,string> $params
     */
    public function run(Request $request, array $params): Response
    {
        foreach ($this->middleware as $mw) {
            $result = $mw($request);
            if ($result instanceof Response) {
                return $result;
            }
        }
        return ($this->handler)($params);
    }

    /**
     * Match a path; returns captured params, or null if it doesn't match.
     *
     * @return array<string,string>|null
     */
    public function match(string $path): ?array
    {
        if (preg_match($this->regex(), $path, $matches) !== 1) {
            return null;
        }
        return array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
    }

    /**
     * Generate a URL from this route's pattern. Named params fill placeholders;
     * any extras become a query string.
     *
     * @param array<string,mixed> $params
     */
    public function url(array $params = []): string
    {
        $path = preg_replace_callback('#\{(\w+)\}#', static function (array $m) use (&$params): string {
            $key = $m[1];
            if (!array_key_exists($key, $params)) {
                throw new \RuntimeException("Missing route parameter: {$key}");
            }
            $value = $params[$key];
            unset($params[$key]);
            return rawurlencode((string) $value);
        }, $this->pattern);

        return $params === [] ? $path : $path . '?' . http_build_query($params);
    }

    private function regex(): string
    {
        $regex = preg_replace('#\{(\w+)\}#', '(?P<$1>[^/]+)', $this->pattern);
        return '#^' . $regex . '/?$#';
    }
}

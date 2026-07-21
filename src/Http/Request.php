<?php

declare(strict_types=1);

namespace Nimbus\Http;

/** Read-only view over the current request. */
final class Request
{
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        private array $query,
        private array $post,
        private array $server,
        private array $files,
    ) {
    }

    public static function fromGlobals(): self
    {
        $uri  = $_SERVER['REQUEST_URI'] ?? '/';
        $path = rawurldecode(parse_url($uri, PHP_URL_PATH) ?: '/');
        return new self(
            strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            '/' . trim($path, '/'),
            $_GET,
            $_POST,
            $_SERVER,
            $_FILES,
        );
    }

    public function isPost(): bool
    {
        return $this->method === 'POST';
    }

    public function query(string $key, ?string $default = null): ?string
    {
        return isset($this->query[$key]) && !is_array($this->query[$key]) ? (string) $this->query[$key] : $default;
    }

    public function input(string $key, ?string $default = null): ?string
    {
        return isset($this->post[$key]) && !is_array($this->post[$key]) ? (string) $this->post[$key] : $default;
    }

    /** @return array<string,mixed> */
    public function all(): array
    {
        return $this->post;
    }

    public function header(string $name): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        return isset($this->server[$key]) ? (string) $this->server[$key] : null;
    }

    /**
     * Client IP for throttling. Uses REMOTE_ADDR only — X-Forwarded-For is
     * spoofable, so trusting it needs explicit trusted-proxy config (roadmap).
     */
    public function ip(): string
    {
        return (string) ($this->server['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    public function bearerToken(): ?string
    {
        $header = $this->header('Authorization') ?? '';
        return preg_match('/^Bearer\s+(.+)$/i', $header, $m) ? trim($m[1]) : null;
    }

    /** @return array<string,mixed>|null */
    public function file(string $key): ?array
    {
        return $this->files[$key] ?? null;
    }
}

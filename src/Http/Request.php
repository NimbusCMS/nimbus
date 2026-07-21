<?php

declare(strict_types=1);

namespace Nimbus\Http;

use Nimbus\Support\Config;

/** Read-only view over the current request. */
final class Request
{
    private TrustedProxies $proxies;

    /**
     * @param array<string,mixed> $query
     * @param array<string,mixed> $post
     * @param array<string,mixed> $server
     * @param array<string,mixed> $files
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        private array $query,
        private array $post,
        private array $server,
        private array $files,
        ?TrustedProxies $proxies = null,
    ) {
        $this->proxies = $proxies ?? new TrustedProxies();
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
            TrustedProxies::fromString(Config::trustedProxies()),
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
     * The client IP, used for throttling.
     *
     * X-Forwarded-For is spoofable by anyone, so it counts only when the
     * immediate peer is a configured trusted proxy. In that case we walk the
     * chain right-to-left and take the first hop we don't recognise: the
     * rightmost entries were appended by our own infrastructure, and anything
     * further left may have been forged by the client.
     */
    public function ip(): string
    {
        $remote = (string) ($this->server['REMOTE_ADDR'] ?? '0.0.0.0');
        if (!$this->proxies->trusts($remote)) {
            return $remote;
        }

        $chain = array_reverse(array_filter(array_map('trim', explode(',', (string) $this->header('X-Forwarded-For')))));
        foreach ($chain as $hop) {
            $hop = self::stripPort($hop);
            if ($hop !== '' && !$this->proxies->trusts($hop)) {
                return $hop;
            }
        }
        return $remote;
    }

    /** Whether the *original* request was over HTTPS. Drives the session cookie's secure flag. */
    public function isSecure(): bool
    {
        $https = strtolower((string) ($this->server['HTTPS'] ?? ''));
        if ($https !== '' && $https !== 'off') {
            return true;
        }
        if ((string) ($this->server['SERVER_PORT'] ?? '') === '443') {
            return true;
        }
        if ($this->proxies->trusts((string) ($this->server['REMOTE_ADDR'] ?? ''))) {
            return strtolower((string) $this->header('X-Forwarded-Proto')) === 'https';
        }
        return false;
    }

    /** `1.2.3.4:5678` -> `1.2.3.4`; IPv6 (which is colon-heavy) is left alone unless bracketed. */
    private static function stripPort(string $hop): string
    {
        if (str_starts_with($hop, '[')) {
            return (string) strstr(ltrim($hop, '['), ']', true);
        }
        return substr_count($hop, ':') === 1 ? (string) strstr($hop, ':', true) : $hop;
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

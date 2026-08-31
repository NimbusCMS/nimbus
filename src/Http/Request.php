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
     * @param array<string,mixed> $cookies
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        private array $query,
        private array $post,
        private array $server,
        private array $files,
        ?TrustedProxies $proxies = null,
        private string $rawBody = '',
        private array $cookies = [],
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
            (string) file_get_contents('php://input'),
            $_COOKIE,
        );
    }

    /** A request cookie by name, or the default — for a public route reading its own cookie (a cart token). */
    public function cookie(string $name, ?string $default = null): ?string
    {
        return isset($this->cookies[$name]) && !is_array($this->cookies[$name]) ? (string) $this->cookies[$name] : $default;
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
     * The unparsed request body. A webhook receiver (ADR 0017) needs the exact
     * bytes to verify a provider's HMAC signature — `json()`/`all()` would have
     * re-encoded them. Read it, verify the signature over it, and only then parse.
     */
    public function rawBody(): string
    {
        return $this->rawBody;
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

    /**
     * Whether this request reached us through a configured trusted proxy (its
     * immediate peer is in TRUSTED_PROXIES) — i.e. a real load-balanced
     * deployment rather than a direct hit. Used only to decide whether a
     * URL-generation misconfiguration is worth warning about
     * ({@see \Nimbus\Support\DeploymentCheck}); it never influences a generated
     * link. Deliberately there is NO accessor for a forwarded *host*: that value
     * is client-spoofable even behind a correct proxy, so nothing may build a URL
     * from it — APP_URL stays the single authority.
     */
    public function viaTrustedProxy(): bool
    {
        return $this->proxies->trusts((string) ($this->server['REMOTE_ADDR'] ?? ''));
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

    /**
     * The decoded JSON request body as an associative array — the API's write
     * transport. An absent, non-object, or malformed body reads as an empty
     * array (the endpoint's validation then rejects it), never a fatal.
     *
     * @return array<string,mixed>
     */
    public function json(): array
    {
        if (trim($this->rawBody) === '') {
            return [];
        }
        $decoded = json_decode($this->rawBody, true);

        return is_array($decoded) ? $decoded : [];
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

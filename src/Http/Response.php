<?php

declare(strict_types=1);

namespace Nimbus\Http;

use InvalidArgumentException;

/**
 * An immutable HTTP response. Controllers build one and return it; the kernel
 * sends it. This keeps header()/echo/exit out of application code — there is
 * exactly one place output happens (send()).
 *
 * Header names and values are validated on the way in, so a response can never
 * carry a header-injection payload into send(). Deliberately not PSR-7: no
 * streams, no message interface, no factories.
 */
final class Response
{
    /** Statuses that may carry a Location. */
    private const REDIRECT_STATUSES = [301, 302, 303, 307, 308];

    /** RFC 7230 token characters — the only thing allowed in a header name. */
    private const HEADER_NAME = '/^[!#$%&\'*+\-.^_`|~0-9A-Za-z]+$/';

    /** @param array<string,string> $headers */
    private function __construct(
        public readonly int $status,
        public readonly string $body = '',
        public readonly array $headers = [],
    ) {
        foreach ($headers as $name => $value) {
            self::assertValidHeader((string) $name, $value);
        }
    }

    public static function html(string $body, int $status = 200): self
    {
        return new self($status, $body, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    /**
     * SECURITY: `$to` is validated only for header safety (the constructor rejects
     * CR/LF/NUL) — NOT for destination. Every current caller passes a trusted
     * internal path (`Url::to`, a hardcoded route, or operator-owned
     * `config/redirects.php`), so there is no open redirect today. Do NOT pass a
     * user-influenced target (a `next`/`return_to` query param) here without first
     * forcing it relative — reject `//host`, any `scheme://`, and `scheme:` URIs —
     * or it becomes an open redirect (HTTP-7). Add that guard alongside the feature
     * that needs it.
     */
    public static function redirect(string $to, int $status = 302): self
    {
        if (!in_array($status, self::REDIRECT_STATUSES, true)) {
            throw new InvalidArgumentException("Not a redirect status: {$status}.");
        }
        return new self($status, '', ['Location' => $to]);
    }

    /** Encoding failures propagate as JsonException rather than sending "false". */
    public static function json(mixed $data, int $status = 200): self
    {
        return new self(
            $status,
            json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ['Content-Type' => 'application/json; charset=UTF-8'],
        );
    }

    /**
     * A response with an explicit content type — for static assets and other
     * non-HTML, non-attachment bodies. The body is held in memory, so this suits
     * the small files a theme ships (CSS/JS/images), not large downloads.
     */
    public static function file(string $content, string $contentType, int $status = 200): self
    {
        return new self($status, $content, ['Content-Type' => $contentType]);
    }

    public static function download(string $content, string $filename, string $contentType = 'application/octet-stream'): self
    {
        return new self(200, $content, [
            'Content-Type'        => $contentType,
            'Content-Disposition' => self::contentDisposition($filename),
        ]);
    }

    /** Replaces any existing header of the same name, regardless of case. */
    public function withHeader(string $name, string $value): self
    {
        self::assertValidHeader($name, $value);

        $headers = $this->headers;
        foreach (array_keys($headers) as $existing) {
            if (strcasecmp((string) $existing, $name) === 0) {
                unset($headers[$existing]);
            }
        }
        $headers[$name] = $value;

        return new self($this->status, $this->body, $headers);
    }

    /**
     * The same response with its body removed — the reply to a HEAD request:
     * identical status and headers as the GET would return, no body. (RFC 9110
     * §9.3.2.) The kernel applies this after building the GET response.
     */
    public function withoutBody(): self
    {
        return new self($this->status, '', $this->headers);
    }

    /** Case-insensitive header lookup. */
    public function header(string $name): ?string
    {
        foreach ($this->headers as $existing => $value) {
            if (strcasecmp((string) $existing, $name) === 0) {
                return $value;
            }
        }
        return null;
    }

    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);
            foreach ($this->headers as $name => $value) {
                header("{$name}: {$value}");
            }
        }
        echo $this->body;
    }

    /**
     * Attachment filename. User agents only reliably read plain ASCII from
     * `filename`, so non-ASCII names get a sanitized fallback plus an RFC 5987
     * `filename*` carrying the real thing.
     */
    private static function contentDisposition(string $filename): string
    {
        $filename = str_replace(["\r", "\n", "\0", '/', '\\'], '', $filename);
        $filename = trim($filename) !== '' ? $filename : 'download';

        $ascii = (string) preg_replace('/[^\x20-\x7E]/', '_', $filename);
        $ascii = str_replace(['"', ';'], '', $ascii);
        $ascii = trim($ascii) !== '' ? $ascii : 'download';

        $disposition = 'attachment; filename="' . $ascii . '"';
        if ($ascii !== $filename) {
            $disposition .= "; filename*=UTF-8''" . rawurlencode($filename);
        }
        return $disposition;
    }

    private static function assertValidHeader(string $name, string $value): void
    {
        if (preg_match(self::HEADER_NAME, $name) !== 1) {
            throw new InvalidArgumentException("Invalid header name: {$name}");
        }
        if (preg_match('/[\r\n\0]/', $value) === 1) {
            throw new InvalidArgumentException("Header {$name} must not contain CR, LF, or NUL.");
        }
    }
}

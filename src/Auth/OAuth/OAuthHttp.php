<?php

declare(strict_types=1);

namespace Nimbus\Auth\OAuth;

/**
 * The two server-to-server calls every provider makes — a form POST (token
 * exchange) and a bearer GET (userinfo) — over **TLS-verified** curl. TLS
 * verification is the whole basis for trusting provider responses without local
 * JWT validation (ADR 0012), so it is never disabled. Non-2xx or transport
 * failure throws {@see OAuthException}; error text never includes a secret.
 */
final class OAuthHttp
{
    private const TIMEOUT = 10;
    private const AGENT = 'NimbusCMS';

    /**
     * @param array<string,string> $fields
     * @return array<string,mixed>
     */
    public static function postForm(string $url, array $fields): array
    {
        return self::json($url, [
            CURLOPT_POST       => true,
            CURLOPT_POSTFIELDS => http_build_query($fields),
            CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/x-www-form-urlencoded'],
        ]);
    }

    /**
     * @param list<string> $extraHeaders
     * @return array<string,mixed>
     */
    public static function getJson(string $url, string $bearer, array $extraHeaders = []): array
    {
        return self::json($url, [
            CURLOPT_HTTPGET    => true,
            CURLOPT_HTTPHEADER => array_merge(['Accept: application/json', 'Authorization: Bearer ' . $bearer], $extraHeaders),
        ]);
    }

    /**
     * @param array<int,mixed> $opts
     * @return array<string,mixed>
     */
    private static function json(string $url, array $opts): array
    {
        if (!str_starts_with(strtolower($url), 'https://')) {
            throw new OAuthException('Provider endpoint must be https.');
        }

        $ch = curl_init($url);
        if ($ch === false) {
            throw new OAuthException('Could not initialise the provider request.');
        }

        curl_setopt_array($ch, $opts + [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_USERAGENT      => self::AGENT,
            CURLOPT_SSL_VERIFYPEER => true,  // never disabled — provenance depends on it
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $body   = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error  = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new OAuthException('Provider request failed: ' . $error);
        }
        if ($status < 200 || $status >= 300) {
            throw new OAuthException("Provider returned HTTP {$status}.");
        }

        $decoded = json_decode((string) $body, true);
        if (!is_array($decoded)) {
            throw new OAuthException('Provider returned an unreadable response.');
        }
        /** @var array<string,mixed> $decoded */
        return $decoded;
    }
}

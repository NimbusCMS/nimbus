<?php

declare(strict_types=1);

namespace Nimbus\Http;

/**
 * Baseline security response headers, applied to every response by the kernel.
 *
 * The CSP keeps 'unsafe-inline' for now because the admin inlines its stylesheet
 * and a little JS; it still blocks external scripts, objects, framing, base-uri
 * hijacking and cross-origin form posts. Tightening to nonces is a roadmap item.
 */
final class SecurityHeaders
{
    /** @return array<string,string> */
    public static function all(): array
    {
        $csp = implode('; ', [
            "default-src 'self'",
            "img-src 'self' data:",
            "style-src 'self' 'unsafe-inline'",
            "script-src 'self' 'unsafe-inline'",
            "object-src 'none'",
            "base-uri 'self'",
            "frame-ancestors 'none'",
            "form-action 'self'",
        ]);

        return [
            'Content-Security-Policy' => $csp,
            'X-Content-Type-Options'  => 'nosniff',
            'X-Frame-Options'         => 'DENY',
            'Referrer-Policy'         => 'same-origin',
        ];
    }

    public static function apply(Response $response): Response
    {
        foreach (self::all() as $name => $value) {
            $response = $response->withHeader($name, $value);
        }
        return $response;
    }
}

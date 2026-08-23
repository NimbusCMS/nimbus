<?php

declare(strict_types=1);

namespace Nimbus\Support;

use Nimbus\Http\Request;

/**
 * Deployment misconfiguration warnings for the admin diagnostics view.
 *
 * Every absolute URL Nimbus generates — password-reset and invitation email
 * links, the OAuth redirect URL, canonical/sitemap URLs — is built from
 * `APP_URL`, never from the request Host (which is client-spoofable and would
 * otherwise let an attacker point a reset link at their own domain). The cost of
 * that safe design is a silent footgun: a deployment behind a TLS-terminating
 * proxy that forgets to set `APP_URL` generates broken `http://localhost` links.
 *
 * These checks catch exactly that, using only signals an attacker cannot forge:
 * whether the peer is a *trusted* proxy, the request scheme (itself proxy-gated
 * in {@see Request::isSecure()}), and the operator's own `APP_URL`. A forwarded
 * host is never read or displayed.
 */
final class DeploymentCheck
{
    private const LOOPBACK = ['localhost', '127.0.0.1', '::1', '0.0.0.0'];

    /**
     * Warnings to surface on the admin diagnostics page. Empty for a healthy or
     * non-proxied install.
     *
     * @return list<string>
     */
    public static function warnings(Request $request): array
    {
        // Only meaningful for a real load-balanced deployment; a direct hit
        // (local dev, no proxy) is not a misconfiguration.
        if (!$request->viaTrustedProxy()) {
            return [];
        }

        $appUrl = Config::appUrl();
        $host   = strtolower((string) parse_url($appUrl, PHP_URL_HOST));
        $scheme = strtolower((string) parse_url($appUrl, PHP_URL_SCHEME));

        if ($host === '' || in_array($host, self::LOOPBACK, true)) {
            return ['APP_URL is a localhost address (' . $appUrl . ') but requests are arriving through a '
                . 'trusted proxy. Password-reset and invitation email links and the OAuth redirect URL are '
                . 'built from APP_URL, so they will point at localhost and fail. Set APP_URL to your public origin.'];
        }

        if ($scheme === 'http' && $request->isSecure()) {
            return ['Requests are arriving over HTTPS through a trusted proxy, but APP_URL uses http://. '
                . 'Generated links — including password-reset emails — will be insecure. '
                . 'Set APP_URL to the https:// origin.'];
        }

        return [];
    }
}

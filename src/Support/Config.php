<?php

declare(strict_types=1);

namespace Nimbus\Support;

/** Typed accessors over environment configuration. */
final class Config
{
    /** @return array{host:string,port:int,name:string,user:string,pass:string} */
    public static function db(): array
    {
        return [
            'host' => (string) Env::get('DB_HOST', '127.0.0.1'),
            'port' => (int) Env::get('DB_PORT', '3306'),
            'name' => (string) Env::get('DB_NAME', 'nimbus'),
            'user' => (string) Env::get('DB_USER', 'root'),
            'pass' => (string) Env::get('DB_PASS', ''),
        ];
    }

    public static function appName(): string
    {
        return (string) Env::get('APP_NAME', 'NimbusCMS');
    }

    /**
     * Demo mode: a public, shared, periodically-reset instance (e.g. the live
     * "try it" sandbox). Off by default. When on, the admin shows a persistent
     * banner and refuses account-destructive self-service (change-password) so a
     * visitor can't lock others out between resets — enforced at the handler,
     * never merely hidden in the UI.
     */
    public static function demo(): bool
    {
        return Env::bool('NIMBUS_DEMO', false);
    }

    /**
     * The published demo credentials, shown pre-filled on the sign-in page in
     * demo mode so a visitor can log in with one click. Only meaningful when
     * {@see demo()} is true; the password is intentionally public.
     */
    public static function demoEmail(): string
    {
        return (string) Env::get('NIMBUS_DEMO_EMAIL', '');
    }

    public static function demoPassword(): string
    {
        return (string) Env::get('NIMBUS_DEMO_PASSWORD', '');
    }

    public static function appUrl(): string
    {
        return rtrim((string) Env::get('APP_URL', 'http://localhost:8080'), '/');
    }

    public static function debug(): bool
    {
        return Env::bool('APP_DEBUG', false);
    }

    /**
     * Outgoing-mail transport: `log` (default — writes to a file, no delivery),
     * `native` (PHP mail()), or `api` (a transactional provider's HTTPS API).
     * The default needs no configuration, so a fresh install and CI work as-is.
     */
    public static function mailTransport(): string
    {
        return strtolower((string) Env::get('MAIL_TRANSPORT', 'log'));
    }

    /** The From address for outgoing mail. */
    public static function mailFrom(): string
    {
        $from = trim((string) Env::get('MAIL_FROM', ''));
        if ($from !== '') {
            return $from;
        }
        $host = parse_url(self::appUrl(), PHP_URL_HOST);
        return 'no-reply@' . (is_string($host) && $host !== '' ? $host : 'localhost');
    }

    /** Bearer API key for the `api` mail transport (a secret — never logged). */
    public static function mailApiKey(): string
    {
        return (string) Env::get('MAIL_API_KEY', '');
    }

    /**
     * HTTPS endpoint for the `api` transport. Defaults to a Resend-compatible
     * `POST {from,to,subject,text}`; override for another provider of that shape.
     */
    public static function mailApiEndpoint(): string
    {
        return (string) Env::get('MAIL_API_ENDPOINT', 'https://api.resend.com/emails');
    }

    /** Where the `log` transport writes captured mail. */
    public static function mailLogPath(): string
    {
        return self::basePath() . '/storage/mail/mail.log';
    }

    /**
     * OAuth SSO providers that are configured (ADR 0012). A provider appears here
     * only when BOTH its client id and secret are present — an unconfigured
     * provider is off, and SSO as a whole is off when this is empty (the default,
     * so a fresh install has no external login). The secret is read here and is
     * never rendered front-channel or logged.
     *
     * @return array<string,array{id:string,secret:string}> keyed by provider
     */
    public static function oauthProviders(): array
    {
        $out = [];
        foreach (['google', 'github'] as $provider) {
            $prefix = 'OAUTH_' . strtoupper($provider);
            $id     = trim((string) Env::get($prefix . '_CLIENT_ID', ''));
            $secret = trim((string) Env::get($prefix . '_CLIENT_SECRET', ''));
            if ($id !== '' && $secret !== '') {
                $out[$provider] = ['id' => $id, 'secret' => $secret];
            }
        }
        return $out;
    }

    /**
     * Comma-separated IPs/CIDRs allowed to set X-Forwarded-*. Empty (default)
     * means forwarded headers are ignored — see Http\TrustedProxies.
     */
    public static function trustedProxies(): string
    {
        return (string) Env::get('TRUSTED_PROXIES', '');
    }

    /** Filesystem directory (relative to project root) where uploads are written. */
    public static function uploadDir(): string
    {
        return (string) Env::get('UPLOAD_DIR', 'public/uploads');
    }

    /** Public URL prefix that maps to the upload directory. */
    public static function uploadUrl(): string
    {
        return (string) Env::get('UPLOAD_URL', '/uploads');
    }

    /** Largest accepted upload, in bytes. Defaults to 10 MB. */
    public static function uploadMaxBytes(): int
    {
        return max(1, (int) Env::get('UPLOAD_MAX_BYTES', (string) (10 * 1024 * 1024)));
    }

    /** Absolute path to the upload directory. */
    public static function uploadPath(): string
    {
        $dir = self::uploadDir();
        return str_starts_with($dir, '/') ? $dir : self::basePath() . '/' . $dir;
    }

    /**
     * Enabled plugins, by plugin id. A plugin absent from this file is enabled
     * by default — installing it was already a deliberate act. Listing it as
     * false disables it without uninstalling, which is how an administrator
     * recovers from a plugin that breaks their site.
     *
     * @return array<string,bool>
     */
    public static function enabledPlugins(): array
    {
        $file = self::basePath() . '/config/plugins.php';
        if (!is_file($file)) {
            return [];
        }
        $enabled = require $file;
        return is_array($enabled) ? $enabled : [];
    }

    /**
     * The active public theme name, read from config/theme.php — consistent
     * with how config/plugins.php configures plugins. A missing or malformed
     * file falls back to the bundled 'starter' theme, so a fresh install
     * renders without any configuration.
     */
    public static function theme(): string
    {
        $file = self::basePath() . '/config/theme.php';
        if (!is_file($file)) {
            return 'starter';
        }
        $name = require $file;
        return is_string($name) && $name !== '' ? $name : 'starter';
    }

    /** Absolute path to the active theme's directory (themes/{name}). */
    public static function themePath(): string
    {
        return self::basePath() . '/themes/' . self::theme();
    }

    /**
     * The site-wide default meta/Open-Graph description, from config/site.php's
     * `description` key — used for pages that supply none of their own. Empty
     * when unset.
     */
    public static function siteDescription(): string
    {
        $file = self::basePath() . '/config/site.php';
        if (!is_file($file)) {
            return '';
        }
        $site = require $file;
        $description = is_array($site) ? ($site['description'] ?? '') : '';
        return is_string($description) ? $description : '';
    }

    /**
     * The **default** named navigation menus, read from config/menus.php — each
     * menu a list of `{label, url}` items a theme renders (`main` for the header,
     * `footer` for the footer). Malformed entries are dropped rather than trusted,
     * so a typo in config never reaches a template. These are the seed/fallback:
     * the admin Menus editor ({@see \Nimbus\Site\Menus}) overrides a menu per name
     * in the DB, and {@see \Nimbus\Site\Menus::all} merges the two.
     *
     * @return array<string,list<array{label:string,url:string}>>
     */
    public static function menus(): array
    {
        $file = self::basePath() . '/config/menus.php';
        if (!is_file($file)) {
            return [];
        }
        $raw = require $file;
        if (!is_array($raw)) {
            return [];
        }

        $menus = [];
        foreach ($raw as $name => $items) {
            if (!is_string($name) || !is_array($items)) {
                continue;
            }
            $clean = [];
            foreach ($items as $item) {
                if (is_array($item) && isset($item['label'], $item['url']) && is_string($item['label']) && is_string($item['url'])) {
                    $clean[] = ['label' => $item['label'], 'url' => $item['url']];
                }
            }
            if ($clean !== []) {
                $menus[$name] = $clean;
            }
        }
        return $menus;
    }

    /**
     * The collection rendered at the site root (`/`), read from config/site.php,
     * or null when no home is configured (the root then shows a placeholder).
     *
     * A `single`-kind collection renders its one live entry; a regular
     * collection renders its entry index. Keeping this in config — not a column
     * on a collection — models the fact that a site has exactly one home, and
     * keeps site-wide settings in one place, consistent with config/theme.php.
     */
    public static function home(): ?string
    {
        $file = self::basePath() . '/config/site.php';
        if (!is_file($file)) {
            return null;
        }
        $site = require $file;
        if (!is_array($site)) {
            return null;
        }
        $home = $site['home'] ?? null;
        return is_string($home) && $home !== '' ? $home : null;
    }

    /**
     * URL redirects, read from config/redirects.php — an exact source path maps
     * to a destination, applied before routing. A value is either a destination
     * string (a 301) or `['to' => …, 'status' => 301|302|307|308]`. Malformed
     * entries are dropped. Editor-managed redirects are a later capability.
     *
     * @return array<string,array{to:string,status:int}>
     */
    public static function redirects(): array
    {
        $file = self::basePath() . '/config/redirects.php';
        return self::normalizeRedirects(is_file($file) ? require $file : []);
    }

    /**
     * Validate a raw redirect map into `from => {to, status}`, dropping anything
     * malformed so a config typo can never break routing.
     *
     * @internal exposed to test the validation without a file on disk.
     * @return array<string,array{to:string,status:int}>
     */
    public static function normalizeRedirects(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $from => $target) {
            if (!is_string($from) || $from === '') {
                continue;
            }
            if (is_string($target)) {
                [$to, $status] = [$target, 301];
            } elseif (is_array($target) && isset($target['to']) && is_string($target['to'])) {
                $to     = $target['to'];
                $status = (isset($target['status']) && is_int($target['status'])) ? $target['status'] : 301;
            } else {
                continue;
            }
            if ($to === '' || !in_array($status, [301, 302, 307, 308], true)) {
                continue;
            }
            $out[$from] = ['to' => $to, 'status' => $status];
        }
        return $out;
    }

    /**
     * Seconds a rendered public page may be cached. 0 (the default) disables
     * page caching entirely. A positive value opts in and bounds how long a
     * time-based change (a scheduled entry going live) can be stale.
     */
    public static function pageCacheTtl(): int
    {
        return max(0, (int) Env::get('PAGE_CACHE_TTL', '0'));
    }

    /** Directory the page cache writes to (under the gitignored storage/). */
    public static function pageCachePath(): string
    {
        return self::basePath() . '/storage/cache/pages';
    }

    // --------------------------------------------------------------- API limits

    /** Per-token request quota within the rate window; 0 disables the token quota. */
    public static function apiRateLimit(): int
    {
        return max(0, (int) Env::get('API_RATE_LIMIT', '120'));
    }

    /** Per-IP flood ceiling within the rate window (catches no-token / invalid floods); 0 disables. */
    public static function apiFloodLimit(): int
    {
        return max(0, (int) Env::get('API_FLOOD_LIMIT', '300'));
    }

    /** The rate-limit window, in seconds. */
    public static function apiRateWindow(): int
    {
        return max(1, (int) Env::get('API_RATE_WINDOW', '60'));
    }

    /** Comma-separated CORS origin allow-list for the API (`*` allows any); empty = same-origin only. */
    public static function corsOrigins(): string
    {
        return (string) Env::get('CORS_ALLOWED_ORIGINS', '');
    }

    public static function basePath(): string
    {
        return dirname(__DIR__, 2);
    }
}

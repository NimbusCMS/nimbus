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

    public static function appUrl(): string
    {
        return rtrim((string) Env::get('APP_URL', 'http://localhost:8080'), '/');
    }

    public static function debug(): bool
    {
        return Env::bool('APP_DEBUG', false);
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

    public static function basePath(): string
    {
        return dirname(__DIR__, 2);
    }
}

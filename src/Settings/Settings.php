<?php

declare(strict_types=1);

namespace Nimbus\Settings;

/**
 * The read/write service for site settings — the one place the rest of the app
 * resolves a setting. A value is the **stored row when one exists** (even an
 * explicit empty string, e.g. "no home page"), otherwise the registry
 * **default** (from `config/site.php`). `array_key_exists` — not `??` — makes
 * that distinction: an admin who clears a field stores `''` and means it, while
 * an untouched setting falls through to the file default.
 *
 * Reads are public: `home` and `description` render on the public site to
 * anonymous visitors, so this service carries no capability gate. Writing is
 * gated (`settings:write`) at the admin form and the MCP tool — this service
 * only persists an already-validated value.
 *
 * The stored map is read once and memoized for the life of the instance (one
 * per request), so a page that resolves several settings makes a single query.
 */
final class Settings
{
    /** @var array<string,string>|null */
    private ?array $stored = null;

    public function __construct(
        private SettingsRepository $repo,
        private SettingsRegistry $registry,
    ) {
    }

    public function get(string $key): string
    {
        $this->stored ??= $this->repo->all();
        if (array_key_exists($key, $this->stored)) {
            return $this->stored[$key];
        }
        $setting = $this->registry->find($key);
        return $setting !== null ? $setting->default : '';
    }

    /** The site title / brand name — never empty (validated on write; the file default backs it). */
    public function title(): string
    {
        return $this->get('site.title');
    }

    /** The home collection handle, or null when none is set (root shows a placeholder). */
    public function home(): ?string
    {
        $handle = $this->get('site.home');
        return $handle !== '' ? $handle : null;
    }

    /** The site-wide default meta/Open-Graph description. */
    public function description(): string
    {
        return $this->get('site.description');
    }

    /**
     * Persist an already-validated value. Callers must validate through the
     * registry first ({@see Setting::validate}); this drops the memo so a
     * subsequent read in the same request sees the new value.
     */
    public function set(string $key, string $value): void
    {
        $this->repo->set($key, $value);
        $this->stored = null;
    }
}

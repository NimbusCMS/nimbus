<?php

declare(strict_types=1);

namespace Nimbus\Site;

use Nimbus\Support\Config;

/**
 * The public themes installed on this site — the directories under `themes/`,
 * each a theme (a `theme.json` plus `templates/` and `assets/`). Discovery is a
 * directory scan, nothing more: a theme is installed by putting it there, the
 * same deliberate, reviewable act as installing a plugin via Composer.
 *
 * This is the allow-list behind the site-theme setting (a chosen theme must be
 * one that actually exists), so a theme **name is validated to a safe slug** here
 * — the name becomes a filesystem path (`themes/{name}`), and a value like
 * `../secret` must never be treated as a theme. Only `[a-z0-9-]` directory names
 * that contain a `theme.json` are ever returned.
 */
final class ThemeCatalog
{
    private string $dir;

    public function __construct(?string $themesDir = null)
    {
        $this->dir = $themesDir ?? Config::basePath() . '/themes';
    }

    /**
     * Installed themes, keyed by their (slug) directory name, each with the
     * display name + description from its `theme.json` (falling back to the slug).
     *
     * @return array<string,array{name:string,description:string}>
     */
    public function installed(): array
    {
        if (!is_dir($this->dir)) {
            return [];
        }
        $out = [];
        foreach ((array) scandir($this->dir) as $entry) {
            if (!is_string($entry) || preg_match('/^[a-z0-9-]+$/', $entry) !== 1) {
                continue; // skip ., .., and any non-slug name — it can't be a valid theme
            }
            $manifest = $this->dir . '/' . $entry . '/theme.json';
            if (!is_file($manifest)) {
                continue;
            }
            $meta = json_decode((string) file_get_contents($manifest), true);
            $meta = is_array($meta) ? $meta : [];
            $out[$entry] = [
                'name'        => is_string($meta['name'] ?? null) && $meta['name'] !== '' ? $meta['name'] : ucfirst($entry),
                'description' => is_string($meta['description'] ?? null) ? $meta['description'] : '',
            ];
        }
        ksort($out);
        return $out;
    }

    /** Whether a theme by this exact name is installed — the setting's allow-list check. */
    public function has(string $name): bool
    {
        return array_key_exists($name, $this->installed());
    }

    /**
     * The safe on-disk directory for a chosen theme, or null if it isn't a real,
     * installed theme. The chosen name becomes a filesystem path, so this is the
     * containment gate: the name must be a known installed slug **and** its
     * resolved real path must sit inside the themes directory — a stale choice or
     * a `../…` value returns null, and the caller falls back to its default.
     */
    public function dirFor(string $name): ?string
    {
        if (!$this->has($name)) {
            return null;
        }
        $real = realpath($this->dir . '/' . $name);
        $root = realpath($this->dir);
        if ($real === false || $root === false || !is_dir($real) || !str_starts_with($real . DIRECTORY_SEPARATOR, $root . DIRECTORY_SEPARATOR)) {
            return null;
        }
        return $real;
    }
}

<?php

declare(strict_types=1);

namespace Nimbus\Settings;

use Nimbus\Content\CollectionRepository;
use Nimbus\Support\Config;

/**
 * The typed registry of known site settings — the allow-list boundary for the
 * whole store. Reads and writes only ever go through the keys defined here, so
 * an arbitrary or hostile key (`settings[role]=admin`, a traversal-shaped key)
 * has nowhere to land: it is not in the registry, so it is neither defaulted nor
 * written. This is the same "validate-a-value-that-selects-a-code-path at the
 * boundary" pattern used by the admin-theme allow-list.
 *
 * Defaults come from `config/site.php` via {@see Config}, keeping the file the
 * shipped default and the database a per-install override (see the file↔DB
 * boundary in the review-loop principles). Adding a setting is one entry here
 * plus a read via {@see Settings}; the admin form and the MCP tool both derive
 * their fields from this registry, so they can never drift apart.
 */
final class SettingsRegistry
{
    /** A generous cap for a meta-description value — long enough for SEO, bounded against abuse. */
    public const MAX_DESCRIPTION = 500;

    /** A site title is a short brand string; bound it well under any sink's limit. */
    public const MAX_TITLE = 80;

    /** @var array<string,Setting> */
    private array $settings;

    public function __construct(CollectionRepository $collections)
    {
        $this->settings = [
            'site.title' => new Setting(
                'site.title',
                'text',
                'Site title',
                'The name shown in the admin, on your public site, and in Open Graph tags.',
                Config::appName(),
                static function (string $value): ?string {
                    if ($value === '') {
                        return 'A site title is required.';
                    }
                    return mb_strlen($value) <= self::MAX_TITLE
                        ? null
                        : 'Keep the title under ' . self::MAX_TITLE . ' characters.';
                },
            ),
            'site.home' => new Setting(
                'site.home',
                'collection',
                'Home page',
                'The collection shown at your site root ( / ). Leave blank for the default placeholder.',
                (string) (Config::home() ?? ''),
                static function (string $value) use ($collections): ?string {
                    if ($value === '') {
                        return null; // blank = no home page, a valid choice
                    }
                    return $collections->findByHandle($value) !== null
                        ? null
                        : "No collection has the handle \"{$value}\".";
                },
            ),
            'site.description' => new Setting(
                'site.description',
                'textarea',
                'Site description',
                'Default meta & Open Graph description for pages that supply none of their own.',
                Config::siteDescription(),
                static fn (string $value): ?string => mb_strlen($value) <= self::MAX_DESCRIPTION
                    ? null
                    : 'Keep the description under ' . self::MAX_DESCRIPTION . ' characters.',
            ),
        ];
    }

    /** @return array<string,Setting> the known settings, in display order */
    public function all(): array
    {
        return $this->settings;
    }

    public function find(string $key): ?Setting
    {
        return $this->settings[$key] ?? null;
    }
}

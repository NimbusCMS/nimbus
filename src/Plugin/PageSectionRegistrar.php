<?php

declare(strict_types=1);

namespace Nimbus\Plugin;

use InvalidArgumentException;
use Nimbus\Site\PageSectionRegistry;
use Nimbus\Site\PageView;

/**
 * The **page-section** capability, as a plugin sees it (ADR 0023) — a themed,
 * SEO-friendly public page at a pretty top-level handle, the counterpart to the
 * `/ext` action routes (ADR 0017) for pages rather than actions.
 *
 * ```php
 * $ctx->pages()->register('shop', $resolve, __DIR__ . '/../templates');
 * ```
 *
 * The resolver receives the Request and returns a {@see PageView} (which template,
 * data, meta, status) or null (→ the themed 404). Core renders it through the
 * ACTIVE theme; the plugin's `$templatesPath` supplies default templates that a
 * theme overrides (theme-first resolution). The plugin renders no HTML and owns
 * no layout — that stays the theme's.
 *
 * Containment (the security review's gate): the handle is a single safe URL
 * segment and may not shadow a core route or a reserved content handle, so a
 * plugin can never claim `/admin`, `/api`, `/ext`, … — a bad handle throws and
 * the plugin fails its load (parity with the other registrars). A section handle
 * also should not duplicate an existing content collection's handle; because the
 * section mounts before the `{collection}` catch-all it would otherwise silently
 * shadow that collection (a footgun, not a privilege — the deterministic
 * mount-order is the containment; a bidirectional runtime guard is a tracked
 * follow-up).
 */
final class PageSectionRegistrar
{
    /**
     * Handles a section may not claim: the reserved content handles (which already
     * include admin/api/ext/theme/uploads and the management resources) plus the
     * remaining core route first-segments and the literal site files. A collision
     * here would let a plugin shadow a core surface, so it is refused at
     * registration, before the route is ever mounted.
     */
    private const RESERVED = [
        // reserved content handles (CollectionService::RESERVED_COLLECTION_HANDLES)
        'schema', 'media', 'users', 'tokens', 'settings', 'roles', 'admin',
        'api', 'uploads', 'theme', 'ext',
        // remaining core admin/auth route first-segments
        'login', 'logout', 'dashboard', 'plugins', 'collections', 'menus',
        'oauth', 'forgot', 'reset', 'accept',
        // literal public-site paths
        'sitemap', 'robots', 'llms',
    ];

    public function __construct(
        private PageSectionRegistry $registry,
        private string $pluginId,
    ) {
    }

    /**
     * @param callable(\Nimbus\Http\Request):?PageView $resolver
     * @param ?string $templatesPath absolute path to the plugin's default-templates
     *                               directory (theme templates override these), or null
     */
    public function register(string $handle, callable $resolver, ?string $templatesPath = null): void
    {
        if (preg_match('/^[a-z0-9-]+$/', $handle) !== 1) {
            throw new InvalidArgumentException("A page-section handle must be lowercase letters, digits or hyphens: \"{$handle}\".");
        }
        if (in_array($handle, self::RESERVED, true)) {
            throw new InvalidArgumentException("The page-section handle \"{$handle}\" is reserved by a core route or content handle.");
        }
        if ($templatesPath !== null && !is_dir($templatesPath)) {
            throw new InvalidArgumentException("The page-section templates path does not exist: \"{$templatesPath}\".");
        }
        $this->registry->add($handle, $resolver, $templatesPath, $this->pluginId);
    }
}

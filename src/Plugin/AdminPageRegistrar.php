<?php

declare(strict_types=1);

namespace Nimbus\Plugin;

use InvalidArgumentException;
use Nimbus\Admin\AdminPageRegistry;

/**
 * The admin-page capability, as a plugin sees it.
 *
 * A plugin registers a page under `/admin/{slug}`: a sidebar label + icon and a
 * handler. The handler receives the Request and returns either HTML — which core
 * wraps in the authenticated admin shell (sidebar, top bar) — or a full Response.
 * The page is login-gated and shown in the sidebar like a core section.
 *
 * The slug is bound to a safe URL segment, and the registration is stamped with
 * the plugin's id (by the loader), so it cannot be spoofed and rolls back on a
 * failed load. Mirrors the other registrars.
 */
final class AdminPageRegistrar
{
    public function __construct(
        private AdminPageRegistry $registry,
        private string $pluginId,
    ) {
    }

    /**
     * @param callable(\Nimbus\Http\Request):(string|\Nimbus\Http\Response) $handler
     */
    public function register(string $slug, string $label, string $icon, callable $handler): void
    {
        if (preg_match('/^[a-z0-9-]+$/', $slug) !== 1) {
            throw new InvalidArgumentException("An admin page slug must be lowercase letters, digits or hyphens: \"{$slug}\".");
        }
        $this->registry->add($slug, $label, $icon, $handler, $this->pluginId);
    }
}

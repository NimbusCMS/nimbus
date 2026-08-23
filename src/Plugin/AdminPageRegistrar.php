<?php

declare(strict_types=1);

namespace Nimbus\Plugin;

use InvalidArgumentException;
use Nimbus\Admin\AdminPageRegistry;
use Nimbus\Auth\Authorizer;

/**
 * The admin-page capability, as a plugin sees it.
 *
 * A plugin registers a page under `/admin/{slug}`: a sidebar label + icon and a
 * handler. The handler receives the Request and the page's CSP nonce, and returns
 * either HTML — which core wraps in the authenticated admin shell (sidebar, top
 * bar) — or a full Response. The nonce argument is additive: a handler that only
 * declares the Request keeps working. The page is shown in the sidebar like a
 * core section.
 *
 * By default a page is **login-only** (any signed-in user). A page that does
 * privileged work may require a capability: pass `admin` or a core management
 * capability (`{schema|media|users|tokens|settings|roles}:{read|write}`). Those
 * are the only accepted values — a content-shaped capability (e.g. `posts:read`)
 * is rejected, because the content wildcard `*:read` would satisfy it and the
 * gate would not actually restrict the page. Plugin-defined capabilities are not
 * supported yet (they would need to be grantable in the Roles UI).
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
     * @param callable(\Nimbus\Http\Request,string):(string|\Nimbus\Http\Response) $handler
     *     receives the Request and the CSP nonce; a 1-argument handler is still valid
     * @param ?string $capability null = login-only; else `admin` or a core
     *                            management `{resource}:{read|write}`
     */
    public function register(string $slug, string $label, string $icon, callable $handler, ?string $capability = null): void
    {
        if (preg_match('/^[a-z0-9-]+$/', $slug) !== 1) {
            throw new InvalidArgumentException("An admin page slug must be lowercase letters, digits or hyphens: \"{$slug}\".");
        }
        if ($capability !== null && !self::isGateableCapability($capability)) {
            throw new InvalidArgumentException("An admin page capability must be \"admin\" or a management capability (e.g. \"settings:read\"), not \"{$capability}\".");
        }
        $this->registry->add($slug, $label, $icon, $handler, $this->pluginId, $capability);
    }

    /**
     * Only `admin` and the exact-or-admin management capabilities are accepted —
     * these are wildcard-immune (the content `*:action` grant never satisfies
     * them), so the page is genuinely restricted.
     */
    private static function isGateableCapability(string $capability): bool
    {
        if ($capability === 'admin') {
            return true;
        }
        $parts = explode(':', $capability, 2);

        return count($parts) === 2
            && in_array($parts[0], Authorizer::MANAGEMENT, true)
            && in_array($parts[1], ['read', 'write'], true);
    }
}

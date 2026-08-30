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
 * privileged work may require a capability: pass `admin`, a core management
 * capability (`{schema|media|users|tokens|settings|roles}:{read|write}`), or
 * **this plugin's own capability** (`{pluginId}:{read|write}`, ADR 0020) — the
 * grantable, wildcard-immune capability the plugin declared (ADR 0015). Those are
 * the only accepted values — a content-shaped capability (e.g. `posts:read`) is
 * rejected, because the content wildcard `*:read` would satisfy it and the gate
 * would not actually restrict the page. For a *plugin* capability a page may gate
 * only on its **own** (resource = its id) — never another plugin's — so it can
 * protect its money-grade admin actions exactly as its MCP tools already are.
 *
 * The slug is bound to a safe URL segment, and the registration is stamped with
 * the plugin's id (by the loader), so it cannot be spoofed and rolls back on a
 * failed load. Mirrors the other registrars.
 */
final class AdminPageRegistrar
{
    /**
     * Core admin route first-segments a plugin page may not shadow — a colliding
     * slug would register an unreachable route behind a live nav entry that opens
     * the core page (PLUG-4). Kept in sync with the real routes by a drift-guard
     * test that derives the segments from `Router::routes()`.
     */
    private const RESERVED_SLUGS = [
        'login', 'logout', 'dashboard', 'plugins', 'collections', 'media',
        'users', 'roles', 'tokens', 'settings', 'oauth', 'forgot', 'reset', 'accept',
    ];

    public function __construct(
        private AdminPageRegistry $registry,
        private string $pluginId,
    ) {
    }

    /**
     * @param callable(\Nimbus\Http\Request,string):(string|\Nimbus\Http\Response) $handler
     *     receives the Request and the CSP nonce; a 1-argument handler is still valid
     * @param ?string $capability null = login-only; else `admin`, a core
     *                            management `{resource}:{read|write}`, or this
     *                            plugin's own `{pluginId}:{read|write}` (ADR 0020)
     */
    public function register(string $slug, string $label, string $icon, callable $handler, ?string $capability = null): void
    {
        if (preg_match('/^[a-z0-9-]+$/', $slug) !== 1) {
            throw new InvalidArgumentException("An admin page slug must be lowercase letters, digits or hyphens: \"{$slug}\".");
        }
        if (in_array($slug, self::RESERVED_SLUGS, true)) {
            throw new InvalidArgumentException("The admin page slug \"{$slug}\" is reserved by a core section. Prefix it with your plugin name (e.g. \"myplugin-{$slug}\").");
        }
        if ($capability !== null && !$this->isGateableCapability($capability)) {
            throw new InvalidArgumentException("An admin page capability must be \"admin\", a core management capability (e.g. \"settings:read\"), or this plugin's own capability (\"{$this->pluginId}:read\"/\":write\"), not \"{$capability}\".");
        }
        $this->registry->add($slug, $label, $icon, $handler, $this->pluginId, $capability);
    }

    /**
     * Register a POST form handler for one of this plugin's admin pages (H3),
     * served at `/admin/{page}/{action}`. Core enforces auth, the page's capability
     * and CSRF before calling `$handler`, which does the work and returns a Response
     * (typically a redirect back to the page). The handler is passed the Request.
     *
     * @param callable(\Nimbus\Http\Request):\Nimbus\Http\Response $handler
     */
    public function action(string $page, string $action, callable $handler): void
    {
        if (preg_match('/^[a-z0-9-]+$/', $page) !== 1 || preg_match('/^[a-z0-9-]+$/', $action) !== 1) {
            throw new InvalidArgumentException("An admin action page and name must be lowercase letters, digits or hyphens: \"{$page}/{$action}\".");
        }
        $this->registry->addAction($page, $action, $handler, $this->pluginId);
    }

    /**
     * A gateable capability is `admin`, a **core** management capability, or **this
     * plugin's own** capability (`{pluginId}:{read|write}`) — all wildcard-immune
     * (the content `*:action` grant never satisfies them), so the page is genuinely
     * restricted.
     *
     * The own-capability case is validated *structurally* here (resource = this
     * plugin's id), not against the {@see \Nimbus\Auth\CapabilityRegistry}: a page
     * is registered *during* `register()`, but plugin capabilities aren't frozen
     * into the {@see Authorizer} until every plugin has loaded (ADR 0015), so
     * existence can't be confirmed at registration without an ordering trap. That
     * is safe because enforcement is fail-safe: {@see \Nimbus\Auth\Gate::holdsPageGate()}
     * honours a plugin capability only once it is a frozen management resource, and
     * refuses an undeclared one to everyone but `admin` — never falling through to
     * the content wildcard (ADR 0020).
     */
    private function isGateableCapability(string $capability): bool
    {
        if ($capability === 'admin') {
            return true;
        }
        $parts = explode(':', $capability, 2);
        if (count($parts) !== 2 || !in_array($parts[1], ['read', 'write'], true)) {
            return false;
        }

        return in_array($parts[0], Authorizer::MANAGEMENT, true) || $parts[0] === $this->pluginId;
    }
}

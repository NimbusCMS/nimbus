<?php

declare(strict_types=1);

namespace Nimbus\Admin;

use Nimbus\Auth\Auth;
use Nimbus\Database\Connection;
use Nimbus\Http\Csp;
use Nimbus\Http\Csrf;
use Nimbus\Http\Request;
use Nimbus\Http\Response;
use Nimbus\Http\Router;
use Nimbus\Http\Url;
use Nimbus\Settings\Settings;

/**
 * Routes the admin pages plugins registered.
 *
 * Each registered page becomes `GET /admin/{slug}`, login-gated by the shared
 * auth middleware. The plugin's handler returns HTML — wrapped here in the admin
 * shell, with its sidebar entry active — or a full Response (a download, a
 * redirect), which is passed straight through.
 *
 * Registered last, so a plugin slug can never shadow a core admin route: the
 * literal core routes match first.
 */
final class PluginPagesController extends Controller
{
    public function __construct(Connection $db, Auth $auth, Settings $settings, AdminPageRegistry $adminPages)
    {
        parent::__construct($db, $auth, $settings, $adminPages);
    }

    public function routes(Router $r): void
    {
        $pages = $this->adminPages?->all() ?? [];
        if ($pages === []) {
            return;
        }

        $actions = $this->adminPages?->actions() ?? [];
        // Each action inherits the capability of the page it belongs to.
        $capabilityOf = [];
        foreach ($pages as $page) {
            $capabilityOf[$page['slug']] = $page['capability'];
        }

        $r->group('/admin', [$this->authMw], function (Router $g) use ($pages, $actions, $capabilityOf): void {
            foreach ($pages as $page) {
                $slug       = $page['slug'];
                $handler    = $page['handler'];
                $capability = $page['capability'];
                $g->get('/' . $slug, fn (Request $req, array $p): Response => $this->render($slug, $handler, $capability, $req))->name('admin.plugin.' . $slug);
            }
            foreach ($actions as $a) {
                $handler    = $a['handler'];
                $capability = $capabilityOf[$a['page']] ?? null;
                $g->post('/' . $a['page'] . '/' . $a['action'], fn (Request $req, array $p): Response => $this->runAction($handler, $capability, $a['page'], $req))
                    ->name('admin.plugin.' . $a['page'] . '.' . $a['action']);
            }
        });
    }

    /**
     * A plugin admin form POST (H3). Core enforces the boundary before the plugin
     * runs: the page's capability via {@see Gate::holdsPageGate()} (wildcard-immune,
     * like the GET — `admin`, a core management cap, or the plugin's own frozen
     * capability; ADR 0020), then CSRF — so a plugin cannot ship an unauthenticated,
     * under-privileged, or CSRF-unprotected admin write.
     * The handler does its work and returns a Response (typically a redirect back
     * to its page with a fixed status code).
     */
    private function runAction(callable $handler, ?string $capability, string $page, Request $request): Response
    {
        if (!$this->gate->holdsPageGate($capability)) {
            $this->abortTo(Url::to('admin.dashboard'));
        }
        $this->requireCsrf($request, '/admin/' . $page);

        return $handler($request);
    }

    /**
     * A handler returns HTML (wrapped in the admin shell) or a full Response
     * (passed straight through — a download, a redirect). A page that declared a
     * capability is gated here (the route, not just the nav) via
     * {@see Gate::holdsPageGate()} — the declared cap is `admin`, a core management
     * capability, or the plugin's own frozen capability (ADR 0020), all
     * wildcard-immune.
     *
     * The handler is passed the CSP nonce as a second argument, so a page that
     * emits an inline `<script nonce>` can run under the admin CSP. It is additive:
     * a handler declaring only the Request ignores the extra argument.
     */
    private function render(string $slug, callable $handler, ?string $capability, Request $request): Response
    {
        if (!$this->gate->holdsPageGate($capability)) {
            $this->abortTo(Url::to('admin.dashboard'));
        }

        // The handler receives the CSP nonce and a CSRF token — so a page that
        // renders a form (H3) can embed a valid `_token` and post to its action.
        // Additive: a handler declaring fewer parameters ignores the extras.
        $result = $handler($request, Csp::nonce(), Csrf::token());

        return $result instanceof Response ? $result : $this->shell($slug, (string) $result);
    }
}

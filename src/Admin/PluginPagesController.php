<?php

declare(strict_types=1);

namespace Nimbus\Admin;

use Nimbus\Auth\Auth;
use Nimbus\Database\Connection;
use Nimbus\Http\Request;
use Nimbus\Http\Response;
use Nimbus\Http\Router;
use Nimbus\Http\Url;

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
    public function __construct(Connection $db, Auth $auth, AdminPageRegistry $adminPages)
    {
        parent::__construct($db, $auth, $adminPages);
    }

    public function routes(Router $r): void
    {
        $pages = $this->adminPages?->all() ?? [];
        if ($pages === []) {
            return;
        }

        $r->group('/admin', [$this->authMw], function (Router $g) use ($pages): void {
            foreach ($pages as $page) {
                $slug       = $page['slug'];
                $handler    = $page['handler'];
                $capability = $page['capability'];
                $g->get('/' . $slug, fn (Request $req, array $p): Response => $this->render($slug, $handler, $capability, $req))->name('admin.plugin.' . $slug);
            }
        });
    }

    /**
     * A handler returns HTML (wrapped in the admin shell) or a full Response
     * (passed straight through — a download, a redirect). A page that declared a
     * capability is gated here (the route, not just the nav) — the declared cap
     * is validated at registration to be `admin` or a management capability, so
     * it is wildcard-immune.
     */
    private function render(string $slug, callable $handler, ?string $capability, Request $request): Response
    {
        if ($capability !== null && !$this->gate->holds($capability)) {
            $this->abortTo(Url::to('admin.dashboard'));
        }

        $result = $handler($request);

        return $result instanceof Response ? $result : $this->shell($slug, (string) $result);
    }
}

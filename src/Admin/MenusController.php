<?php

declare(strict_types=1);

namespace Nimbus\Admin;

use Nimbus\Auth\Auth;
use Nimbus\Database\Connection;
use Nimbus\Http\Csrf;
use Nimbus\Http\Request;
use Nimbus\Http\Response;
use Nimbus\Http\Router;
use Nimbus\Http\Url;
use Nimbus\Settings\Settings;
use Nimbus\Site\Menus;
use Nimbus\Support\CoreEvents;
use Nimbus\Support\EventDispatcher;

/**
 * The admin Menus editor — edit the site's navigation menus (the header `main`
 * menu and the `footer` menu) without touching `config/menus.php`.
 *
 * Menus are DB-backed ({@see Menus}, mirroring Settings): a saved menu overrides
 * the file default; the file stays the seed. Gated on `settings:write` (a
 * management capability — a content `*:write` editor can't reach it) and CSRF.
 * Every link URL is scheme-validated in {@see Menus::save}, so a menu can never
 * carry a `javascript:` payload onto a public page. A save flushes the page cache
 * (via {@see CoreEvents::MENUS_SAVED}) so cached pages pick up the new nav.
 */
final class MenusController extends Controller
{
    private const OK_NOTICES  = ['saved' => 'Menu saved.'];
    private const ERR_NOTICES = ['unknown' => 'That is not an editable menu.'];

    private Menus $menus;

    public function __construct(Connection $db, Auth $auth, Settings $settings, private EventDispatcher $events, ?AdminPageRegistry $adminPages = null)
    {
        parent::__construct($db, $auth, $settings, $adminPages);
        $this->menus = new Menus($db);
    }

    public function routes(Router $r): void
    {
        $r->group('/admin/menus', [$this->authMw], function (Router $g): void {
            $g->get('', fn (Request $req, array $p): Response => $this->index($req))->name('admin.menus');
            $g->post('', fn (Request $req, array $p): Response => $this->save($req));
        });
    }

    private function index(Request $req): Response
    {
        $this->requireCan('settings', 'write', Url::to('admin.dashboard'));

        $all   = $this->menus->all();
        $menus = [];
        foreach (Menus::EDITABLE as $name) {
            $menus[$name] = $all[$name] ?? [];
        }

        return $this->page('menus/index', 'menus', [
            'menus'  => $menus,
            'csrf'   => Csrf::token(),
            'notice' => $this->notice($req, self::OK_NOTICES, self::ERR_NOTICES),
        ]);
    }

    private function save(Request $req): Response
    {
        $this->requireCsrf($req, Url::to('admin.menus'));
        $this->requireCan('settings', 'write', Url::to('admin.menus'));

        $name = trim((string) ($req->input('menu') ?? ''));
        if (!in_array($name, Menus::EDITABLE, true)) {
            return $this->redirect(Url::to('admin.menus') . '?err=unknown');
        }

        // Rows arrive as parallel label[]/url[] arrays; zip them into items. The
        // Menus store validates each URL's scheme and drops empties/unsafe rows.
        $body   = $req->all();
        $labels = is_array($body['label'] ?? null) ? array_values($body['label']) : [];
        $urls   = is_array($body['url'] ?? null) ? array_values($body['url']) : [];

        $items = [];
        foreach ($labels as $i => $label) {
            $items[] = ['label' => (string) $label, 'url' => (string) ($urls[$i] ?? '')];
        }

        $this->menus->save($name, $items);
        $this->events->dispatch(CoreEvents::MENUS_SAVED, ['menu' => $name]);

        return $this->redirect(Url::to('admin.menus') . '?msg=saved');
    }
}

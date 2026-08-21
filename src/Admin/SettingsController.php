<?php

declare(strict_types=1);

namespace Nimbus\Admin;

use Nimbus\Auth\Auth;
use Nimbus\Auth\UserRepository;
use Nimbus\Database\Connection;
use Nimbus\Http\Csrf;
use Nimbus\Http\Request;
use Nimbus\Http\Response;
use Nimbus\Http\Router;
use Nimbus\View\AdminTheme;

/**
 * The admin Settings page. Its first real feature is the per-user **theme
 * picker** (docs/design/admin-experience.md).
 *
 * The theme is a *personal preference*, not a site-management capability: any
 * signed-in user picks their own admin skin, so there is no `requireCan` gate.
 * Two properties keep it safe: the write targets **only the session user's own
 * row** (no request-supplied id — no cross-user write), and the slug is
 * **allow-listed** ({@see AdminTheme::sanitize}) before it is stored and again at
 * render, so it can only ever be a known theme in `<html data-theme>`.
 */
final class SettingsController extends Controller
{
    private UserRepository $users;

    public function __construct(Connection $db, Auth $auth, ?AdminPageRegistry $adminPages = null)
    {
        parent::__construct($db, $auth, $adminPages);
        $this->users = new UserRepository($db);
    }

    public function routes(Router $r): void
    {
        $r->group('/admin/settings', [$this->authMw], function (Router $g): void {
            $g->get('', fn (Request $req, array $p): Response => $this->index($req))->name('admin.settings');
            $g->post('/theme', fn (Request $req, array $p): Response => $this->saveTheme($req));
        });
    }

    private function index(Request $req): Response
    {
        return $this->page('settings/index', 'settings', [
            'themes'  => AdminTheme::THEMES,
            'current' => AdminTheme::sanitize($this->auth->user()?->theme),
            'flash'   => $req->query('flash'),
            'csrf'    => Csrf::token(),
        ]);
    }

    private function saveTheme(Request $req): Response
    {
        $this->requireCsrf($req, '/admin/settings');

        $user = $this->auth->user();
        if ($user !== null) {
            // Allow-list the submitted slug, then write only this user's own row.
            $this->users->setTheme($user->id, AdminTheme::sanitize($req->input('theme')));
        }
        return $this->redirect('/admin/settings?flash=theme');
    }
}

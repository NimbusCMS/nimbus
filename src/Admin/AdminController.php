<?php

declare(strict_types=1);

namespace Nimbus\Admin;

use Nimbus\Http\Csrf;
use Nimbus\Http\Request;
use Nimbus\Http\Response;
use Nimbus\Http\Router;

/**
 * Authentication + dashboard + the not-yet-built section stubs. The admin shell
 * (nav, view) lives in the base Controller; content sections have their own
 * controllers.
 */
final class AdminController extends Controller
{
    public function routes(Router $r): void
    {
        // Public (no auth middleware).
        $r->get('/admin/login', fn (): Response => $this->loginForm())->name('admin.login');
        $r->post('/admin/login', fn (): Response => $this->login());
        $r->post('/admin/logout', fn (): Response => $this->logout())->name('admin.logout');

        // Everything else is gated by the auth middleware.
        $r->group('/admin', [$this->authMw], function (Router $g): void {
            $g->get('', fn (): Response => $this->dashboardPage())->name('admin.dashboard');
            $g->get('/dashboard', fn (): Response => $this->dashboardPage());

            foreach (['media', 'users', 'settings'] as $section) {
                $g->get("/{$section}", fn (): Response => $this->page('stub', $section, ['title' => ucfirst($section)]))->name("admin.{$section}");
            }
        });
    }

    private function loginForm(?string $error = null): Response
    {
        if ($this->auth->check()) {
            return $this->redirect('/admin');
        }
        return $this->bare('login', ['error' => $error, 'csrf' => Csrf::token()]);
    }

    private function login(): Response
    {
        $req = Request::fromGlobals();
        if (!Csrf::check($req->input('_token'))) {
            return $this->loginForm('Your session expired. Please try again.');
        }
        if ($this->auth->attempt((string) $req->input('email'), (string) $req->input('password'))) {
            return $this->redirect('/admin');
        }
        return $this->loginForm('Invalid email or password.');
    }

    private function logout(): Response
    {
        if (Csrf::check(Request::fromGlobals()->input('_token'))) {
            $this->auth->logout();
            $_SESSION = [];
            if (ini_get('session.use_cookies')) {
                $p = session_get_cookie_params();
                setcookie(session_name(), '', ['expires' => time() - 42000, 'path' => $p['path'], 'domain' => $p['domain'], 'secure' => $p['secure'], 'httponly' => $p['httponly'], 'samesite' => $p['samesite']]);
            }
            session_destroy();
        }
        return $this->redirect('/admin/login');
    }

    private function dashboardPage(): Response
    {
        return $this->page('dashboard', 'dashboard', [
            'stats' => [
                'collections' => $this->count('nb_collections'),
                'entries'     => $this->count('nb_entries'),
                'media'       => $this->count('nb_media'),
                'users'       => $this->count('nb_users'),
            ],
        ]);
    }

    private function count(string $table): int
    {
        return (int) ($this->db->selectOne("SELECT COUNT(*) AS c FROM `{$table}`")['c'] ?? 0);
    }
}

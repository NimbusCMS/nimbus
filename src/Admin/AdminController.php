<?php

declare(strict_types=1);

namespace Nimbus\Admin;

use Nimbus\Http\Csrf;
use Nimbus\Http\Request;
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
        $r->get('/admin/login', fn (): string => $this->loginForm());
        $r->post('/admin/login', fn (): string => $this->login());
        $r->get('/admin/logout', fn (): string => $this->logout());
        $r->get('/admin', fn (): string => $this->dashboardPage());
        $r->get('/admin/dashboard', fn (): string => $this->dashboardPage());

        foreach (['media', 'users', 'settings'] as $section) {
            $r->get("/admin/{$section}", function () use ($section): string {
                $this->guard();
                return $this->page('stub', $section, ['title' => ucfirst($section)]);
            });
        }
    }

    private function loginForm(?string $error = null): string
    {
        if ($this->auth->check()) {
            $this->redirect('/admin');
        }
        return $this->view->renderBare('login', ['error' => $error, 'csrf' => Csrf::token()]);
    }

    private function login(): string
    {
        $req = Request::fromGlobals();
        if (!Csrf::check($req->input('_token'))) {
            return $this->loginForm('Your session expired. Please try again.');
        }
        if ($this->auth->attempt((string) $req->input('email'), (string) $req->input('password'))) {
            $this->redirect('/admin');
        }
        return $this->loginForm('Invalid email or password.');
    }

    private function logout(): string
    {
        $this->auth->logout();
        $this->redirect('/admin/login');
    }

    private function dashboardPage(): string
    {
        $this->guard();
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

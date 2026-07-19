<?php

declare(strict_types=1);

namespace Nimbus\Admin;

use Nimbus\Auth\Auth;
use Nimbus\Database\Connection;
use Nimbus\Http\Csrf;
use Nimbus\Http\Request;
use Nimbus\Http\Router;
use Nimbus\Support\Config;
use Nimbus\View\View;

/**
 * The admin area: authentication + dashboard, and the shell (sidebar nav, top
 * bar) that the content/media/user/settings sections plug into. Those sections
 * register their own routes; the ones not yet built render a friendly stub.
 */
final class AdminController
{
    private View $view;

    public function __construct(
        private Connection $db,
        private Auth $auth,
    ) {
        $this->view = new View(dirname(__DIR__) . '/View/themes/nimbus', [
            'auth'    => $auth,
            'appName' => Config::appName(),
        ]);
    }

    public function routes(Router $r): void
    {
        $r->get('/admin/login', fn (): string => $this->loginForm());
        $r->post('/admin/login', fn (): string => $this->login());
        $r->get('/admin/logout', fn (): string => $this->logout());
        $r->get('/admin', fn (): string => $this->guard() ?? $this->dashboard());
        $r->get('/admin/dashboard', fn (): string => $this->guard() ?? $this->dashboard());

        foreach (['collections', 'media', 'users', 'settings'] as $section) {
            $r->get("/admin/{$section}", fn (): string => $this->guard() ?? $this->stub($section));
        }
    }

    /** @return array<int,array<string,mixed>> */
    private function nav(string $active): array
    {
        $items = [
            ['key' => 'dashboard',   'label' => 'Dashboard',   'url' => '/admin',             'icon' => '✦'],
            ['key' => 'collections', 'label' => 'Collections', 'url' => '/admin/collections', 'icon' => '❑'],
            ['key' => 'media',       'label' => 'Media',       'url' => '/admin/media',       'icon' => '❖'],
            ['key' => 'users',       'label' => 'Users',       'url' => '/admin/users',       'icon' => '☾'],
            ['key' => 'settings',    'label' => 'Settings',    'url' => '/admin/settings',    'icon' => '⚙'],
        ];
        foreach ($items as &$item) {
            $item['active'] = $item['key'] === $active;
        }
        return $items;
    }

    private function guard(): ?string
    {
        if (!$this->auth->check()) {
            $this->redirect('/admin/login');
        }
        return null;
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

    private function dashboard(): string
    {
        return $this->view->render('dashboard', [
            'nav'   => $this->nav('dashboard'),
            'stats' => [
                'collections' => $this->count('nb_collections'),
                'entries'     => $this->count('nb_entries'),
                'media'       => $this->count('nb_media'),
                'users'       => $this->count('nb_users'),
            ],
        ]);
    }

    private function stub(string $key): string
    {
        return $this->view->render('stub', [
            'nav'   => $this->nav($key),
            'title' => ucfirst($key),
        ]);
    }

    private function count(string $table): int
    {
        $row = $this->db->selectOne("SELECT COUNT(*) AS c FROM `{$table}`");
        return (int) ($row['c'] ?? 0);
    }

    private function redirect(string $to): never
    {
        header('Location: ' . $to);
        exit;
    }
}

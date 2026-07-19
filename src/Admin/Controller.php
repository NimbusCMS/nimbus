<?php

declare(strict_types=1);

namespace Nimbus\Admin;

use Nimbus\Auth\Auth;
use Nimbus\Database\Connection;
use Nimbus\Support\Config;
use Nimbus\View\View;

/** Shared admin plumbing: the themed view, sidebar nav, auth guard, redirects. */
abstract class Controller
{
    protected View $view;

    public function __construct(
        protected Connection $db,
        protected Auth $auth,
    ) {
        $this->view = new View(dirname(__DIR__) . '/View/themes/nimbus', [
            'auth'    => $auth,
            'appName' => Config::appName(),
        ]);
    }

    /** @return array<int,array<string,mixed>> */
    protected function nav(string $active): array
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

    /** Render a template inside the admin shell. */
    protected function page(string $template, string $navActive, array $data = []): string
    {
        return $this->view->render($template, ['nav' => $this->nav($navActive)] + $data);
    }

    protected function guard(): void
    {
        if (!$this->auth->check()) {
            $this->redirect('/admin/login');
        }
    }

    protected function redirect(string $to): never
    {
        header('Location: ' . $to);
        exit;
    }
}

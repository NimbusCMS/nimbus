<?php

declare(strict_types=1);

namespace Nimbus\Admin;

use Nimbus\Auth\Auth;
use Nimbus\Database\Connection;
use Nimbus\Http\Csrf;
use Nimbus\Http\HttpException;
use Nimbus\Http\Middleware\AuthMiddleware;
use Nimbus\Http\Request;
use Nimbus\Http\Response;
use Nimbus\Support\Config;
use Nimbus\View\View;

/** Shared admin plumbing: the themed view, sidebar nav, auth middleware, redirects. */
abstract class Controller
{
    protected View $view;
    protected AuthMiddleware $authMw;

    public function __construct(
        protected Connection $db,
        protected Auth $auth,
    ) {
        $this->view   = new View(dirname(__DIR__) . '/View/themes/nimbus', [
            'auth'    => $auth,
            'appName' => Config::appName(),
        ]);
        $this->authMw = new AuthMiddleware($auth);
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

    /**
     * Render a template inside the admin shell.
     *
     * @param array<string,mixed> $data
     */
    protected function page(string $template, string $navActive, array $data = []): Response
    {
        return Response::html($this->view->render($template, ['nav' => $this->nav($navActive)] + $data));
    }

    /**
     * Render a template with no shell (login, standalone pages).
     *
     * @param array<string,mixed> $data
     */
    protected function bare(string $template, array $data = []): Response
    {
        return Response::html($this->view->renderBare($template, $data));
    }

    protected function redirect(string $to): Response
    {
        return Response::redirect($to);
    }

    /** Short-circuit the current action with a redirect (throws; caught by the kernel). */
    protected function abortTo(string $to): never
    {
        throw HttpException::redirect($to);
    }

    /**
     * Reject a state-changing request without a valid CSRF token. Shared by
     * every admin controller so no write path can forget it.
     */
    protected function requireCsrf(Request $request, string $abortTo = '/admin/collections'): void
    {
        if (!Csrf::check($request->input('_token'))) {
            $this->abortTo($abortTo);
        }
    }
}

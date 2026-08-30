<?php

declare(strict_types=1);

namespace Nimbus\Admin;

use Nimbus\Auth\Auth;
use Nimbus\Auth\Gate;
use Nimbus\Auth\RoleRepository;
use Nimbus\Database\Connection;
use Nimbus\Http\Csrf;
use Nimbus\Http\HttpException;
use Nimbus\Http\Middleware\AuthMiddleware;
use Nimbus\Http\Request;
use Nimbus\Http\Response;
use Nimbus\Http\Url;
use Nimbus\Settings\Settings;
use Nimbus\Support\Config;
use Nimbus\View\View;

/** Shared admin plumbing: the themed view, sidebar nav, auth middleware, redirects. */
abstract class Controller
{
    protected View $view;
    protected AuthMiddleware $authMw;
    protected Gate $gate;

    /** Memoized site title, resolved lazily on first render (see {@see siteTitle()}). */
    private ?string $siteTitle = null;

    public function __construct(
        protected Connection $db,
        protected Auth $auth,
        protected Settings $settings,
        protected ?AdminPageRegistry $adminPages = null,
    ) {
        $this->view   = new View(dirname(__DIR__) . '/View/themes/nimbus', [
            'auth'    => $auth,
            'appName' => Config::appName(),
            'cspNonce' => \Nimbus\Http\Csp::nonce(),
            'demo'     => Config::demo(),
        ]);
        $this->authMw = new AuthMiddleware($auth);
        $this->gate   = new Gate(new RoleRepository($db), $auth);
    }

    /** @return array<int,array<string,mixed>> */
    protected function nav(string $active): array
    {
        // Content sections link to pages that enforce per-collection read/write
        // (ADR 0011): the Collections index lists only readable collections, and a
        // direct hit on an unreadable one 404-equivalents. Administration sections
        // appear only to holders of the capability that gates them, so there are
        // no dead links.
        $items = [
            ['key' => 'dashboard',   'label' => 'Dashboard',   'url' => Url::to('admin.dashboard'),             'icon' => '✦'],
            ['key' => 'collections', 'label' => 'Collections', 'url' => Url::to('admin.collections.index'), 'icon' => '❑'],
        ];
        if ($this->gate->can('media', 'read')) {
            $items[] = ['key' => 'media', 'label' => 'Media', 'url' => Url::to('admin.media.index'), 'icon' => '❖'];
        }
        if ($this->gate->can('users', 'write')) {
            $items[] = ['key' => 'users', 'label' => 'Users', 'url' => Url::to('admin.users.index'), 'icon' => '☾'];
        }
        if ($this->gate->can('roles', 'write')) {
            $items[] = ['key' => 'roles', 'label' => 'Roles', 'url' => Url::to('admin.roles.index'), 'icon' => '⛨'];
        }
        if ($this->gate->can('tokens', 'write')) {
            $items[] = ['key' => 'tokens', 'label' => 'API tokens', 'url' => Url::to('admin.tokens.index'), 'icon' => '⚿'];
        }
        if ($this->gate->holds('admin')) {
            $items[] = ['key' => 'plugins', 'label' => 'Plugins', 'url' => '/admin/plugins', 'icon' => '⚡'];
        }
        $items[] = ['key' => 'settings', 'label' => 'Settings', 'url' => Url::to('admin.settings'), 'icon' => '⚙'];
        // Plugin-registered pages sit below the core sections; one that declared a
        // capability appears only to holders (no dead links) — the route enforces it too.
        foreach ($this->adminPages?->all() ?? [] as $page) {
            if (!$this->gate->holdsPageGate($page['capability'])) {
                continue;
            }
            $items[] = ['key' => $page['slug'], 'label' => $page['label'], 'url' => '/admin/' . $page['slug'], 'icon' => $page['icon']];
        }
        foreach ($items as &$item) {
            $item['active'] = $item['key'] === $active;
        }
        return $items;
    }

    /**
     * Render already-built HTML content inside the admin shell (sidebar + top
     * bar). For pages whose body is produced outside a theme template — e.g. a
     * plugin's admin page.
     */
    protected function shell(string $navActive, string $content): Response
    {
        $this->view->share('appName', $this->siteTitle());
        return Response::html($this->view->partial('layout', [
            'nav'       => $this->nav($navActive),
            '__content' => $content,
        ]));
    }

    /**
     * Render a template inside the admin shell.
     *
     * @param array<string,mixed> $data
     */
    protected function page(string $template, string $navActive, array $data = []): Response
    {
        $this->view->share('appName', $this->siteTitle());
        return Response::html($this->view->render($template, ['nav' => $this->nav($navActive)] + $data));
    }

    /**
     * Render a template with no shell (login, standalone pages).
     *
     * @param array<string,mixed> $data
     */
    protected function bare(string $template, array $data = []): Response
    {
        $this->view->share('appName', $this->siteTitle());
        return Response::html($this->view->renderBare($template, $data));
    }

    /**
     * The site title, resolved from the settings store (DB override ?? the
     * `.env`/config default) and memoized for this request. Built lazily — only
     * a controller that actually renders pays the one query, so `/api` handlers
     * and redirects do not. Set as a shared View global at render time (so the
     * layout and every nested partial see it), overriding the construction-time
     * `Config::appName()` default.
     */
    protected function siteTitle(): string
    {
        return $this->siteTitle ??= $this->settings->title();
    }

    protected function redirect(string $to): Response
    {
        return Response::redirect($to);
    }

    /**
     * Is a value longer than a column allows? A small shared guard so every admin
     * form rejects over-long input with a friendly message instead of a 1406
     * "Data too long" → 500 under STRICT_TRANS_TABLES. `mb_strlen` counts
     * characters (utf8mb4), matching the column definition.
     */
    protected function tooLong(?string $value, int $max): bool
    {
        return $value !== null && mb_strlen($value) > $max;
    }

    /** Short-circuit the current action with a redirect (throws; caught by the kernel). */
    protected function abortTo(string $to): never
    {
        throw HttpException::redirect($to);
    }

    /**
     * Resolve a post-redirect notice from fixed CODES — never free text
     * (ADMIN-10). `?msg=<code>` is looked up in $ok, `?err=<code>` in $err; an
     * unknown or absent code resolves to `null` (renders nothing). The query
     * carries only a key; the map owns the string — so a crafted `?err=`/`?msg=`
     * link can never paint attacker-chosen text into a trusted admin banner.
     * This is the one blessed admin-notice mechanism (mirrors
     * {@see SettingsController::oauthFlash()}); templates render the resolved
     * `{kind,message}`, never `$_GET` text.
     *
     * @param array<string,string> $ok  success code => fixed message
     * @param array<string,string> $err error code => fixed message
     * @return array{kind:string,message:string}|null
     */
    protected function notice(Request $req, array $ok, array $err): ?array
    {
        $msg = $req->query('msg');
        if ($msg !== null && isset($ok[$msg])) {
            return ['kind' => 'ok', 'message' => $ok[$msg]];
        }
        $error = $req->query('err');
        if ($error !== null && isset($err[$error])) {
            return ['kind' => 'error', 'message' => $err[$error]];
        }
        return null;
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

    /** Restrict an action to holders of the `admin` super-grant, redirecting everyone else. */
    protected function requireAdmin(string $abortTo = '/admin'): void
    {
        if (!$this->gate->holds('admin')) {
            $this->abortTo($abortTo);
        }
    }

    /** Restrict an action to holders of a specific capability (ADR 0011). */
    protected function requireCan(string $resource, string $action, string $abortTo = '/admin'): void
    {
        if (!$this->gate->can($resource, $action)) {
            $this->abortTo($abortTo);
        }
    }
}

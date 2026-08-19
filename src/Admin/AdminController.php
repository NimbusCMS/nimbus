<?php

declare(strict_types=1);

namespace Nimbus\Admin;

use Nimbus\Auth\Auth;
use Nimbus\Auth\LoginThrottle;
use Nimbus\Database\Connection;
use Nimbus\Http\Csrf;
use Nimbus\Http\Request;
use Nimbus\Http\Response;
use Nimbus\Http\Router;
use Nimbus\Plugin\PluginStatus;

/**
 * Authentication + dashboard + the not-yet-built section stubs. The admin shell
 * (nav, view) lives in the base Controller; content sections have their own
 * controllers.
 */
final class AdminController extends Controller
{
    /**
     * @param list<PluginStatus> $pluginStatuses computed once by the kernel at
     *        boot; the controller never reads installed.json itself.
     */
    public function __construct(Connection $db, Auth $auth, private array $pluginStatuses = [], ?AdminPageRegistry $adminPages = null)
    {
        parent::__construct($db, $auth, $adminPages);
    }

    public function routes(Router $r): void
    {
        // Public (no auth middleware).
        $r->get('/admin/login', fn (Request $req, array $p): Response => $this->loginForm())->name('admin.login');
        $r->post('/admin/login', fn (Request $req, array $p): Response => $this->login($req));
        $r->post('/admin/logout', fn (Request $req, array $p): Response => $this->logout($req))->name('admin.logout');

        // Everything else is gated by the auth middleware.
        $r->group('/admin', [$this->authMw], function (Router $g): void {
            $g->get('', fn (Request $req, array $p): Response => $this->dashboardPage())->name('admin.dashboard');
            $g->get('/dashboard', fn (Request $req, array $p): Response => $this->dashboardPage());
            $g->get('/plugins', fn (Request $req, array $p): Response => $this->pluginsPage())->name('admin.plugins');

            // `users` is now a real controller; `settings` is still a stub.
            foreach (['settings'] as $section) {
                $g->get("/{$section}", fn (Request $req, array $p): Response => $this->page('stub', $section, ['title' => ucfirst($section)]))->name("admin.{$section}");
            }
        });
    }

    /**
     * Read-only view of installed plugins. Diagnostic, not an installer: it
     * shows what Composer installed and what the loader made of it, and offers
     * no action. Administrators only — plugin state can name failing packages.
     */
    private function pluginsPage(): Response
    {
        $this->requireAdmin();

        return $this->page('plugins', 'plugins', [
            'plugins'  => $this->pluginStatuses,
            'problems' => array_values(array_filter(
                $this->pluginStatuses,
                static fn (PluginStatus $s): bool => $s->isProblem(),
            )),
        ]);
    }

    private function loginForm(?string $error = null): Response
    {
        if ($this->auth->check()) {
            return $this->redirect('/admin');
        }
        return $this->bare('login', ['error' => $error, 'csrf' => Csrf::token()]);
    }

    private function login(Request $req): Response
    {
        if (!Csrf::check($req->input('_token'))) {
            return $this->loginForm('Your session expired. Please try again.');
        }

        $throttle = new LoginThrottle($this->db);
        $key      = $req->ip();
        if ($throttle->tooManyAttempts($key)) {
            $minutes = (int) ceil($throttle->lockedFor($key) / 60);
            return $this->loginForm("Too many attempts. Try again in {$minutes} minute(s).");
        }

        if ($this->auth->attempt((string) $req->input('email'), (string) $req->input('password'))) {
            $throttle->clear($key);
            return $this->redirect('/admin');
        }

        $throttle->recordFailure($key);
        return $this->loginForm('Invalid email or password.');
    }

    private function logout(Request $req): Response
    {
        if (Csrf::check($req->input('_token'))) {
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

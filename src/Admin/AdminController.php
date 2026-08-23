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
use Nimbus\Http\Url;
use Nimbus\Plugin\PluginStatus;

/**
 * Authentication, the dashboard, and the read-only plugins page. The admin shell
 * (nav, view) lives in the base Controller; every content and settings section
 * has its own controller.
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
        $r->get('/admin/login', fn (Request $req, array $p): Response => $this->loginForm($this->loginError($req), $this->loginNotice($req)))->name('admin.login');
        $r->post('/admin/login', fn (Request $req, array $p): Response => $this->login($req));
        $r->post('/admin/logout', fn (Request $req, array $p): Response => $this->logout($req))->name('admin.logout');

        // Everything else is gated by the auth middleware.
        $r->group('/admin', [$this->authMw], function (Router $g): void {
            $g->get('', fn (Request $req, array $p): Response => $this->dashboardPage())->name('admin.dashboard');
            $g->get('/dashboard', fn (Request $req, array $p): Response => $this->dashboardPage());
            $g->get('/plugins', fn (Request $req, array $p): Response => $this->pluginsPage($req))->name('admin.plugins');
        });
    }

    /**
     * Read-only view of installed plugins. Diagnostic, not an installer: it
     * shows what Composer installed and what the loader made of it, and offers
     * no action. Administrators only — plugin state can name failing packages.
     */
    private function pluginsPage(Request $request): Response
    {
        $this->requireAdmin();

        return $this->page('plugins', 'plugins', [
            'plugins'  => $this->pluginStatuses,
            'problems' => array_values(array_filter(
                $this->pluginStatuses,
                static fn (PluginStatus $s): bool => $s->isProblem(),
            )),
            // Deployment misconfiguration warnings (e.g. APP_URL still localhost
            // behind a proxy → broken reset-email links). Non-spoofable signals
            // only; see DeploymentCheck.
            'warnings' => \Nimbus\Support\DeploymentCheck::warnings($request),
        ]);
    }

    /** A one-line success notice for the login page, from a completed reset or invite acceptance. */
    private function loginNotice(Request $req): ?string
    {
        if ($req->query('reset') !== null) {
            return 'Your password has been reset — please sign in.';
        }
        if ($req->query('welcome') !== null) {
            return 'Your account is ready — please sign in.';
        }
        return null;
    }

    /** A one-line error for the login page from a failed SSO round-trip (see OAuthController). */
    private function loginError(Request $req): ?string
    {
        return match ($req->query('oauth_error')) {
            'unknown'   => 'No Nimbus account is linked to that account. Ask an administrator to invite you.',
            'throttled' => 'Too many attempts. Please try again in a few minutes.',
            'provider'  => 'Sign-in could not be completed. Please try again.',
            'config'    => 'That sign-in method is not available.',
            default     => null,
        };
    }

    /**
     * The configured SSO providers to offer on the login page — key + label only,
     * no secrets. Empty (the default) renders no buttons (ADR 0012).
     *
     * @return list<array{key:string,label:string}>
     */
    private function oauthButtons(): array
    {
        $labels = ['google' => 'Google', 'github' => 'GitHub'];
        $out    = [];
        foreach (array_keys(\Nimbus\Support\Config::oauthProviders()) as $key) {
            $out[] = ['key' => $key, 'label' => $labels[$key] ?? ucfirst($key)];
        }
        return $out;
    }

    private function loginForm(?string $error = null, ?string $notice = null): Response
    {
        if ($this->auth->check()) {
            return $this->redirect(Url::to('admin.dashboard'));
        }
        return $this->bare('login', [
            'error'          => $error,
            'notice'         => $notice,
            'csrf'           => Csrf::token(),
            'oauthProviders' => $this->oauthButtons(),
        ]);
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
            return $this->redirect(Url::to('admin.dashboard'));
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
                setcookie((string) session_name(), '', ['expires' => time() - 42000, 'path' => $p['path'], 'domain' => $p['domain'], 'secure' => $p['secure'], 'httponly' => $p['httponly'], 'samesite' => $p['samesite']]);
            }
            session_destroy();
        }
        return $this->redirect(Url::to('admin.login'));
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
            // The media and users cards link to gated pages; hide each for a user
            // who cannot open it so the dashboard has no dead links (ADR 0011).
            'canMedia' => $this->gate->can('media', 'read'),
            'canUsers' => $this->gate->can('users', 'write'),
        ]);
    }

    private function count(string $table): int
    {
        return (int) ($this->db->selectOne("SELECT COUNT(*) AS c FROM `{$table}`")['c'] ?? 0);
    }
}

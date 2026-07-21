<?php

declare(strict_types=1);

namespace Nimbus;

use Nimbus\Admin\AdminController;
use Nimbus\Admin\CollectionsController;
use Nimbus\Auth\Auth;
use Nimbus\Database\Connection;
use Nimbus\Http\HttpException;
use Nimbus\Http\Request;
use Nimbus\Http\Response;
use Nimbus\Http\Router;
use Nimbus\Http\SecurityHeaders;
use Nimbus\Support\Config;
use Nimbus\Support\Env;
use Nimbus\View\View;

/**
 * The HTTP kernel. Boots config + database, routes the request, and sends the
 * single Response that comes back. Handlers return a Response; auth/permission
 * short-circuits throw HttpException (caught here).
 */
final class Application
{
    private Connection $db;
    private Auth $auth;

    public function __construct()
    {
        Env::load(Config::basePath() . '/.env');
        $this->db   = new Connection(Config::db());
        $this->auth = new Auth($this->db);
    }

    public function run(): void
    {
        $this->startSession();
        SecurityHeaders::apply($this->handle(Request::fromGlobals()))->send();
    }

    private function handle(Request $request): Response
    {
        try {
            if (!$this->db->isReady()) {
                return $this->notice('Database unavailable', 'NimbusCMS can’t reach the database. Check your <code>.env</code> or Docker stack.', 503);
            }
            if (!$this->db->tableExists('nb_users')) {
                return $this->notice('Not installed yet', 'Run <code>php bin/nimbus install</code> to conjure the schema and your first user.', 503);
            }

            $router = new Router();
            (new AdminController($this->db, $this->auth))->routes($router);
            (new CollectionsController($this->db, $this->auth))->routes($router);
            $router->get('/', fn (): Response => $this->home());

            return $router->dispatch($request)
                ?? $this->notice('Not found', 'Nothing lives at <code>' . View::e($request->path) . '</code>.', 404);
        } catch (HttpException $e) {
            return $e->response;
        } catch (\Throwable $e) {
            // Log the full error (with a short reference) but never expose it.
            $ref = bin2hex(random_bytes(4));
            error_log("[nimbus {$ref}] " . $e);
            $message = Config::debug()
                ? View::e($e->getMessage())
                : 'An unexpected error occurred. Reference: <code>' . $ref . '</code>';
            return $this->notice('Something went wrong', $message, 500);
        }
    }

    /** Start the session with secure cookie defaults set BEFORE session_start(). */
    private function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        $https = ($_SERVER['HTTPS'] ?? '') === 'on'
            || ($_SERVER['SERVER_PORT'] ?? '') === '443'
            || strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';

        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        session_name('nimbus_session');
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'httponly' => true,
            'secure'   => $https,
            'samesite' => 'Lax',
        ]);
        session_start();
    }

    private function home(): Response
    {
        return $this->notice(
            Config::appName(),
            'Your public site will render here soon. Head to <a href="/admin">/admin</a> to manage content.'
        );
    }

    private function notice(string $title, string $html, int $status = 200): Response
    {
        $t = View::e($title);
        return Response::html(
            "<!doctype html><meta charset=\"utf-8\"><title>{$t}</title>"
            . '<div style="font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;max-width:640px;margin:14vh auto;padding:0 24px;color:#1e2330">'
            . "<h1 style=\"letter-spacing:-.02em\">{$t}</h1><p style=\"color:#6b7280;line-height:1.6\">{$html}</p></div>",
            $status
        );
    }
}

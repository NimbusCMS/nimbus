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

    /**
     * Defaults to the configured database — pass one in to run the kernel
     * against a different connection (the HTTP-functional tests do this).
     */
    public function __construct(?Connection $db = null, ?Auth $auth = null)
    {
        if ($db === null) {
            Env::load(Config::basePath() . '/.env');
            $db = new Connection(Config::db());
        }
        $this->db   = $db;
        $this->auth = $auth ?? new Auth($this->db);
    }

    public function run(): void
    {
        // The one place globals are read. Everything downstream shares this instance.
        $request = Request::fromGlobals();
        $this->startSession($request->isSecure());
        $this->handle($request)->send();
    }

    /**
     * Route one request to one response. Every exit path returns a Response:
     * no match is a 404, an HttpException becomes its own response, and any
     * other throwable becomes a logged reference plus a generic 500.
     *
     * Security headers are applied here rather than in run(), so error pages
     * carry them too and the functional tests exercise the same path clients do.
     */
    public function handle(Request $request): Response
    {
        return SecurityHeaders::apply($this->respond($request));
    }

    private function respond(Request $request): Response
    {
        try {
            if (!$this->db->isReady()) {
                return $this->notice('Database unavailable', 'NimbusCMS can’t reach the database. Check your <code>.env</code> or Docker stack.', 503);
            }
            if (!$this->db->tableExists('nb_users')) {
                return $this->notice('Not installed yet', 'Run <code>php bin/nimbus install</code> to conjure the schema and your first user.', 503);
            }

            return $this->routes()->dispatch($request)
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

    /**
     * Every route the application serves, in match order. Building this in one
     * place keeps the served routes and the introspected routes identical —
     * the route-contract test asserts against exactly what ships.
     */
    public function routes(): Router
    {
        $router = new Router();
        (new AdminController($this->db, $this->auth))->routes($router);
        (new CollectionsController($this->db, $this->auth))->routes($router);
        $router->get('/', fn (Request $req, array $p): Response => $this->home());
        return $router;
    }

    /** Start the session with secure cookie defaults set BEFORE session_start(). */
    private function startSession(bool $https): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

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

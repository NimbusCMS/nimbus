<?php

declare(strict_types=1);

namespace Nimbus;

use Nimbus\Admin\AdminController;
use Nimbus\Admin\CollectionsController;
use Nimbus\Admin\EntriesController;
use Nimbus\Admin\MediaController;
use Nimbus\Api\ApiController;
use Nimbus\Auth\Auth;
use Nimbus\Content\FieldTypeRegistry;
use Nimbus\Database\Connection;
use Nimbus\Http\HttpException;
use Nimbus\Http\Request;
use Nimbus\Http\Response;
use Nimbus\Http\Router;
use Nimbus\Http\SecurityHeaders;
use Nimbus\Plugin\PluginDiagnostic;
use Nimbus\Plugin\PluginLoader;
use Nimbus\Plugin\PluginStatus;
use Nimbus\Site\SiteController;
use Nimbus\Support\Config;
use Nimbus\Support\Env;
use Nimbus\Support\EventDispatcher;
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
     * Composed once and shared. Anything that registers into these — core types
     * today, plugins next — must receive these exact instances, or registration
     * lands in an object nobody reads.
     */
    private FieldTypeRegistry $fieldTypes;
    private EventDispatcher $events;

    /** @var array<string,array{to:string,status:int}> exact-path redirects, applied before routing */
    private array $redirects;

    /** @var list<PluginDiagnostic> */
    private array $pluginDiagnostics = [];

    /** @var list<PluginStatus> one entry per discovered plugin package */
    private array $pluginStatuses = [];

    /**
     * Defaults to the configured database — pass one in to run the kernel
     * against a different connection (the HTTP-functional tests do this).
     */
    /** @param array<string,array{to:string,status:int}>|null $redirects test seam; defaults to config/redirects.php */
    public function __construct(?Connection $db = null, ?Auth $auth = null, ?array $redirects = null)
    {
        if ($db === null) {
            Env::load(Config::basePath() . '/.env');
            $db = new Connection(Config::db());
        }
        $this->db         = $db;
        $this->auth       = $auth ?? new Auth($this->db);
        $this->fieldTypes = new FieldTypeRegistry();
        $this->events     = new EventDispatcher();
        $this->redirects  = $redirects ?? Config::redirects();

        $this->loadPlugins();
    }

    /**
     * Register enabled plugins before anything reads the registries.
     *
     * A plugin that fails to load is recorded and logged, never fatal: one
     * broken third-party package must not make the admin unreachable, which is
     * also the only way an administrator can get in to disable it.
     */
    private function loadPlugins(): void
    {
        $loader = new PluginLoader(
            Config::basePath() . '/vendor/composer/installed.json',
            Config::enabledPlugins(),
        );
        $this->pluginDiagnostics = $loader->load($this->fieldTypes);
        $this->pluginStatuses    = $loader->statuses();

        foreach ($this->pluginDiagnostics as $diagnostic) {
            if ($diagnostic->isFailure()) {
                error_log('[nimbus plugin] ' . $diagnostic);
            }
        }
    }

    /** @return list<PluginDiagnostic> why installed plugins did not register */
    public function pluginDiagnostics(): array
    {
        return $this->pluginDiagnostics;
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
            // Redirects come from config (no database), so they resolve before
            // the readiness checks — an old URL keeps working during an outage.
            $redirect = $this->redirects[$request->path] ?? null;
            if ($redirect !== null) {
                return Response::redirect($redirect['to'], $redirect['status']);
            }

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
        (new AdminController($this->db, $this->auth, $this->pluginStatuses))->routes($router);
        (new CollectionsController($this->db, $this->auth, $this->fieldTypes))->routes($router);
        (new EntriesController($this->db, $this->auth, $this->fieldTypes, $this->events))->routes($router);
        (new MediaController($this->db, $this->auth))->routes($router);
        (new ApiController($this->db, $this->fieldTypes))->routes($router);
        // Registered last: the public site owns `/` and its {collection} routes
        // match only after every literal /admin and /api route has had its turn,
        // so they can never shadow the application's own surfaces.
        (new SiteController($this->db, $this->fieldTypes, Config::home()))->routes($router);
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

    private function notice(string $title, string $html, int $status = 200): Response
    {
        $t = View::e($title);
        return Response::html(
            "<!doctype html><meta charset=\"utf-8\"><title>{$t}</title>"
            . '<div style="font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;max-width:640px;margin:14vh auto;padding:0 24px;color:#1e2330">'
            . "<h1 style=\"letter-spacing:-.02em\">{$t}</h1><p style=\"color:#6b7280;line-height:1.6\">{$html}</p></div>",
            $status,
        );
    }
}

<?php

declare(strict_types=1);

namespace Nimbus;

use Nimbus\Admin\AdminController;
use Nimbus\Admin\AdminPageRegistry;
use Nimbus\Admin\CollectionsController;
use Nimbus\Admin\EntriesController;
use Nimbus\Admin\MediaController;
use Nimbus\Admin\PluginPagesController;
use Nimbus\Admin\RolesController;
use Nimbus\Admin\SettingsController;
use Nimbus\Admin\TokensController;
use Nimbus\Admin\UsersController;
use Nimbus\Api\ApiAuthContext;
use Nimbus\Api\ApiController;
use Nimbus\Auth\Auth;
use Nimbus\Content\FieldTypeRegistry;
use Nimbus\Database\Connection;
use Nimbus\Database\MigrationRegistry;
use Nimbus\Http\Cors;
use Nimbus\Http\HttpException;
use Nimbus\Http\Request;
use Nimbus\Http\Response;
use Nimbus\Http\Router;
use Nimbus\Http\SecurityHeaders;
use Nimbus\Plugin\PluginCapabilities;
use Nimbus\Plugin\PluginDiagnostic;
use Nimbus\Plugin\PluginLoader;
use Nimbus\Plugin\PluginStatus;
use Nimbus\Site\HeadContributorRegistry;
use Nimbus\Site\SiteController;
use Nimbus\Support\Config;
use Nimbus\Support\CoreEvents;
use Nimbus\Support\Env;
use Nimbus\Support\EventDispatcher;
use Nimbus\Support\MaintenanceRegistry;
use Nimbus\Support\PageCache;
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
    private HeadContributorRegistry $headContributors;
    private MigrationRegistry $migrations;
    private AdminPageRegistry $adminPages;
    private MaintenanceRegistry $maintenance;
    private EventDispatcher $events;

    /** Request-scoped carrier for the authenticated API principal (ADR 0006). */
    private ApiAuthContext $apiAuth;

    /** @var array<string,array{to:string,status:int}> exact-path redirects, applied before routing */
    private array $redirects;

    private PageCache $pageCache;

    /** @var list<PluginDiagnostic> */
    private array $pluginDiagnostics = [];

    /** @var list<PluginStatus> one entry per discovered plugin package */
    private array $pluginStatuses = [];

    /**
     * Defaults to the configured database — pass one in to run the kernel
     * against a different connection (the HTTP-functional tests do this).
     */
    /**
     * @param array<string,array{to:string,status:int}>|null $redirects test seam; defaults to config/redirects.php
     * @param PageCache|null       $pageCache test seam; defaults to the configured cache
     * @param EventDispatcher|null $events    test seam; lets a test observe request.handled
     * @param ApiAuthContext|null  $apiAuth   test seam; lets a test observe the established API principal
     */
    public function __construct(?Connection $db = null, ?Auth $auth = null, ?array $redirects = null, ?PageCache $pageCache = null, ?EventDispatcher $events = null, ?ApiAuthContext $apiAuth = null)
    {
        if ($db === null) {
            Env::load(Config::basePath() . '/.env');
            $db = new Connection(Config::db());
        }
        $this->db         = $db;
        $this->auth       = $auth ?? new Auth($this->db);
        $this->fieldTypes       = new FieldTypeRegistry();
        $this->headContributors = new HeadContributorRegistry();
        $this->migrations       = new MigrationRegistry();
        $this->adminPages       = new AdminPageRegistry();
        $this->maintenance      = new MaintenanceRegistry();
        $this->events           = $events ?? new EventDispatcher();
        $this->apiAuth          = $apiAuth ?? new ApiAuthContext();
        $this->redirects  = $redirects ?? Config::redirects();
        $this->pageCache  = $pageCache ?? new PageCache(Config::pageCachePath(), Config::pageCacheTtl());

        // Any content write flushes the page cache. Full-flush is deliberate: one
        // edit can change an index, a relation, or a shared block elsewhere, so
        // clearing everything is simpler and safer than tracking dependencies.
        $flush = function (): void {
            $this->pageCache->flush();
        };
        $this->events->listen(CoreEvents::ENTRY_SAVED, $flush);
        $this->events->listen(CoreEvents::ENTRY_DELETED, $flush);

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
        $this->pluginDiagnostics = $loader->load(new PluginCapabilities(
            fieldTypes: $this->fieldTypes,
            head: $this->headContributors,
            events: $this->events,
            migrations: $this->migrations,
            adminPages: $this->adminPages,
            maintenance: $this->maintenance,
            db: $this->db,
        ));
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

    /** The migrations enabled plugins declared — handed to the Migrator by the CLI. */
    public function migrationRegistry(): MigrationRegistry
    {
        return $this->migrations;
    }

    /** The maintenance tasks enabled plugins declared — run by `nimbus prune`. */
    public function maintenanceRegistry(): MaintenanceRegistry
    {
        return $this->maintenance;
    }

    /** The field-type registry (core + plugin types) — used by the OpenAPI CLI dump. */
    public function fieldTypeRegistry(): FieldTypeRegistry
    {
        return $this->fieldTypes;
    }

    /**
     * The event dispatcher, with plugin subscribers already registered — so a CLI
     * entrypoint (e.g. `nimbus mcp`) emits the same audited events as the web
     * kernel instead of a bare dispatcher no one is listening to.
     */
    public function events(): EventDispatcher
    {
        return $this->events;
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
        // A browser CORS preflight carries no token, so it is answered before
        // routing/auth. Every actual API response is annotated afterwards; both
        // only act when the Origin is on the configured allow-list.
        $response = Cors::isApiPreflight($request)
            ? Cors::preflight($request)
            : $this->respond($request);
        $response = Cors::decorate(SecurityHeaders::apply($response), $request);
        $this->notifyHandled($request, $response);
        return $response;
    }

    /**
     * Fire the best-effort request.handled event. Guarded by hasListeners so a
     * plugin-free install pays nothing, and isolated so a listener that throws
     * is logged, never allowed to break a response that is already finished.
     */
    private function notifyHandled(Request $request, Response $response): void
    {
        $this->events->emitBestEffort(CoreEvents::REQUEST_HANDLED, ['request' => $request, 'response' => $response]);
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

            // Public pages may be cached. A hit skips routing entirely; a miss
            // renders, then stores only a 200 HTML page. Admin, API and asset
            // paths are never cached (they are per-user, JSON, or self-caching).
            $cacheKey  = $this->cacheKey($request);
            if ($cacheKey !== null) {
                $hit = $this->pageCache->get($cacheKey);
                if ($hit !== null) {
                    return Response::html($hit);
                }
            }

            $response = $this->routes()->dispatch($request)
                ?? $this->notice('Not found', 'Nothing lives at <code>' . View::e($request->path) . '</code>.', 404);

            if ($cacheKey !== null && $response->status === 200 && str_contains((string) $response->header('Content-Type'), 'text/html')) {
                $this->pageCache->put($cacheKey, $response->body);
            }

            return $response;
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
        (new AdminController($this->db, $this->auth, $this->pluginStatuses, $this->adminPages))->routes($router);
        (new CollectionsController($this->db, $this->auth, $this->fieldTypes, $this->adminPages))->routes($router);
        (new EntriesController($this->db, $this->auth, $this->fieldTypes, $this->events, $this->adminPages))->routes($router);
        (new MediaController($this->db, $this->auth, $this->adminPages))->routes($router);
        (new UsersController($this->db, $this->auth, $this->adminPages))->routes($router);
        (new RolesController($this->db, $this->auth, $this->adminPages))->routes($router);
        (new TokensController($this->db, $this->auth, $this->adminPages))->routes($router);
        (new SettingsController($this->db, $this->auth, $this->adminPages))->routes($router);
        // Plugin admin pages, after the core admin controllers so a plugin slug
        // can never shadow a core /admin route.
        (new PluginPagesController($this->db, $this->auth, $this->adminPages))->routes($router);
        (new ApiController($this->db, $this->fieldTypes, $this->apiAuth, $this->events))->routes($router);
        // Registered last: the public site owns `/` and its {collection} routes
        // match only after every literal /admin and /api route has had its turn,
        // so they can never shadow the application's own surfaces.
        (new SiteController($this->db, $this->fieldTypes, Config::home(), null, $this->headContributors))->routes($router);
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

    /**
     * The page-cache key for a request, or null when it must not be cached:
     * a non-GET request, an admin / API / theme-asset path, or caching disabled.
     * Only the `page` query param varies a public page, so nothing else is keyed.
     */
    private function cacheKey(Request $request): ?string
    {
        if ($request->method !== 'GET' || !$this->pageCache->enabled()) {
            return null;
        }
        foreach (['/admin', '/api', '/theme/assets'] as $prefix) {
            if ($request->path === $prefix || str_starts_with($request->path, $prefix . '/')) {
                return null;
            }
        }
        $page = (int) ($request->query('page') ?? 1);
        return $request->path . ($page > 1 ? '?page=' . $page : '');
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

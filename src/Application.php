<?php

declare(strict_types=1);

namespace Nimbus;

use Nimbus\Admin\AdminController;
use Nimbus\Admin\AdminPageRegistry;
use Nimbus\Admin\CollectionsController;
use Nimbus\Admin\EntriesController;
use Nimbus\Admin\MediaController;
use Nimbus\Admin\MenusController;
use Nimbus\Admin\OAuthController;
use Nimbus\Admin\PasswordResetController;
use Nimbus\Admin\PluginPagesController;
use Nimbus\Admin\RolesController;
use Nimbus\Admin\SettingsController;
use Nimbus\Admin\TokensController;
use Nimbus\Admin\UsersController;
use Nimbus\Api\ApiAuthContext;
use Nimbus\Api\ApiController;
use Nimbus\Auth\Auth;
use Nimbus\Auth\Authorizer;
use Nimbus\Auth\CapabilityRegistry;
use Nimbus\Content\CollectionRepository;
use Nimbus\Content\FieldTypeRegistry;
use Nimbus\Database\Connection;
use Nimbus\Database\MigrationRegistry;
use Nimbus\Http\ApiRateLimiter;
use Nimbus\Http\Cors;
use Nimbus\Http\Csp;
use Nimbus\Http\HttpException;
use Nimbus\Http\Middleware\RateLimitMiddleware;
use Nimbus\Http\PluginRouteRegistry;
use Nimbus\Http\Request;
use Nimbus\Http\Response;
use Nimbus\Http\Router;
use Nimbus\Http\SecurityHeaders;
use Nimbus\Http\Url;
use Nimbus\Mail\Mailer;
use Nimbus\Mail\MailerFactory;
use Nimbus\Mcp\Guide\SkillRegistry;
use Nimbus\Mcp\McpToolsetRegistry;
use Nimbus\Plugin\PluginCapabilities;
use Nimbus\Plugin\PluginDiagnostic;
use Nimbus\Plugin\PluginLoader;
use Nimbus\Plugin\PluginStatus;
use Nimbus\Plugin\ServiceRegistry;
use Nimbus\Settings\Settings;
use Nimbus\Settings\SettingsRegistry;
use Nimbus\Settings\SettingsRepository;
use Nimbus\Site\HeadContributorRegistry;
use Nimbus\Site\PageSectionRegistry;
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
    /** The canonical CMS version. The single source of truth — surfaced as the
     *  MCP `serverInfo.version` (ADR 0013) and bumped by the release process. */
    public const VERSION = '0.1.0-alpha';

    /** Above this ?page value a public page is rendered but never cached — an
     *  upper bound on distinct page-cache files an anonymous client can mint
     *  (SVM-1). 1000 × PER_PAGE is far beyond any real paginated depth. */
    private const MAX_CACHEABLE_PAGE = 1000;

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
    private SkillRegistry $skills;
    private CapabilityRegistry $capabilities;
    private McpToolsetRegistry $mcpToolsets;
    private PluginRouteRegistry $pluginRoutes;
    private ServiceRegistry $services;
    private PageSectionRegistry $pageSections;
    private EventDispatcher $events;

    /** Request-scoped carrier for the authenticated API principal (ADR 0006). */
    private ApiAuthContext $apiAuth;

    /** The read/write settings store — composed once (SUP-10) so the shell
     *  title and the handling controller share one memo/one query, and every
     *  write goes through one atomic {@see Settings::setMany}. */
    private Settings $settings;

    /** Outgoing-mail transport (password reset, later notifications). */
    private Mailer $mailer;

    /** Configured SSO providers (ADR 0012); empty when SSO is off (the default). */
    private \Nimbus\Auth\OAuth\OAuthProviders $oauthProviders;

    /** @var array<string,array{to:string,status:int}> exact-path redirects, applied before routing */
    private array $redirects;

    private PageCache $pageCache;

    /** The per-IP API flood guard — built once here, used for the CORS preflight
     *  (HTTP-4) and injected into ApiController for the /api group, so the two
     *  share one config and one DB-backed `ip:` bucket. */
    private RateLimitMiddleware $apiFlood;

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
     * @param Mailer|null          $mailer    test seam; lets a test capture outgoing mail (default: configured transport)
     * @param \Nimbus\Auth\OAuth\OAuthProviders|null $oauthProviders test seam; lets a test drive SSO with a fake provider (default: configured providers)
     */
    public function __construct(?Connection $db = null, ?Auth $auth = null, ?array $redirects = null, ?PageCache $pageCache = null, ?EventDispatcher $events = null, ?ApiAuthContext $apiAuth = null, ?Mailer $mailer = null, ?\Nimbus\Auth\OAuth\OAuthProviders $oauthProviders = null)
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
        $this->skills           = new SkillRegistry();
        $this->capabilities     = new CapabilityRegistry();
        $this->mcpToolsets      = new McpToolsetRegistry();
        $this->pluginRoutes     = new PluginRouteRegistry();
        $this->services         = new ServiceRegistry();
        $this->pageSections     = new PageSectionRegistry();
        $this->events           = $events ?? new EventDispatcher();
        $this->apiAuth          = $apiAuth ?? new ApiAuthContext();
        // Composed after the env/db block above so the registry captures loaded
        // config; construction touches no DB (the query is lazy in get()).
        $this->settings         = new Settings(new SettingsRepository($this->db), new SettingsRegistry(new CollectionRepository($this->db)));
        $this->mailer           = $mailer ?? MailerFactory::fromConfig();
        $this->oauthProviders   = $oauthProviders ?? \Nimbus\Auth\OAuth\OAuthProviders::fromConfig();
        $this->redirects  = $redirects ?? Config::redirects();
        $this->pageCache  = $pageCache ?? new PageCache(Config::pageCachePath(), Config::pageCacheTtl());
        // The per-IP flood guard: one instance, keyed `ip:{ip}`, shared by the
        // preflight branch and the /api group (via ApiController) so a preflight
        // and a real request count into the same bucket.
        $this->apiFlood   = new RateLimitMiddleware(
            new ApiRateLimiter($this->db),
            Config::apiFloodLimit(),
            Config::apiRateWindow(),
            static fn (Request $req): string => 'ip:' . $req->ip(),
        );

        // Any content write flushes the page cache. Full-flush is deliberate: one
        // edit can change an index, a relation, or a shared block elsewhere, so
        // clearing everything is simpler and safer than tracking dependencies.
        // This is also security-load-bearing: a cached page's CSP nonce is stable
        // for the entry's life, and the flush is what rotates it on every write —
        // so a payload written knowing the old nonce never meets it (see Csp).
        $flush = function (): void {
            $this->pageCache->flush();
        };
        $this->events->listen(CoreEvents::ENTRY_SAVED, $flush);
        $this->events->listen(CoreEvents::ENTRY_DELETED, $flush);
        // A menu edit changes the nav on every (cached) page — flush so it shows.
        $this->events->listen(CoreEvents::MENUS_SAVED, $flush);

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
            skills: $this->skills,
            capabilities: $this->capabilities,
            mcpToolsets: $this->mcpToolsets,
            routes: $this->pluginRoutes,
            services: $this->services,
            pageSections: $this->pageSections,
            db: $this->db,
        ));
        $this->pluginStatuses    = $loader->statuses();

        // Freeze the plugin-declared management capabilities into the Authorizer
        // for the rest of the process (ADR 0015). This is the ONE caller of
        // useManagement() — a drift test holds that invariant — so the authorization
        // vocabulary is sealed here, at boot, and never mutated at request time.
        Authorizer::useManagement($this->capabilities->managementResources());

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

    /** The agent-guidance fragments enabled plugins declared (ADR 0013) — served as MCP guide resources. */
    public function agentSkills(): SkillRegistry
    {
        return $this->skills;
    }

    /** The MCP toolsets enabled plugins registered (ADR 0016) — composed into the server after the core ones. */
    public function mcpToolsets(): McpToolsetRegistry
    {
        return $this->mcpToolsets;
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

    /** The composed-once settings store (SUP-10) — exposed like {@see events()}
     *  so an alternate entrypoint can share the one instance rather than
     *  hand-building another. */
    public function settings(): Settings
    {
        return $this->settings;
    }

    public function run(): void
    {
        // The one place globals are read. Everything downstream shares this instance.
        $request = Request::fromGlobals();
        // The API is bearer-only and never reads $_SESSION, so don't mint a
        // session cookie for it (or for the CORS preflight, which is an /api
        // OPTIONS) — it would be an unused ambient credential (HTTP-3).
        if (!Cors::isApiPath($request->path)) {
            $this->startSession($request->isSecure());
        }
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
        // Fresh CSP nonce per request, before anything renders — so the value in
        // every inline <script nonce> matches the script-src directive.
        Csp::rotate();

        // A browser CORS preflight carries no token, so it is answered before
        // routing/auth. It still passes the per-IP flood guard (HTTP-4) so it is
        // not an uncounted request class — but fail-open: the guard hits the DB,
        // and the preflight runs before respond()'s try/catch and the readiness
        // gates, so a DB outage or not-yet-installed site must still answer 204
        // rather than throw (a real API request 503s pre-limiter anyway).
        if (Cors::isApiPreflight($request)) {
            try {
                $limited = ($this->apiFlood)($request);
            } catch (\Throwable $e) {
                $ref = bin2hex(random_bytes(4));
                error_log("[nimbus {$ref}] preflight flood-guard skipped: " . $e);
                $limited = null;
            }
            $response = $limited ?? Cors::preflight($request);
        } else {
            $response = $this->respond($request);
        }

        $response = Cors::decorate(SecurityHeaders::apply($response), $request);
        // A HEAD reply carries the GET's status and headers but no body (RFC 9110).
        // Strip it here — after the headers are set, before notifyHandled — so
        // request.handled listeners see exactly what is sent.
        if ($request->method === 'HEAD') {
            $response = $response->withoutBody();
        }
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
                    // Re-emit the nonce baked into the cached HTML so the CSP
                    // header matches it — otherwise every inline script on a
                    // cached page would be blocked by the fresh per-request nonce.
                    Csp::adopt($hit['nonce']);
                    return Response::html($hit['html']);
                }
            }

            $router = $this->routes();
            Url::bind($router); // named-route URL generation for controller redirects
            $response = $router->dispatch($request)
                ?? $this->notice('Not found', 'Nothing lives at <code>' . View::e($request->path) . '</code>.', 404);

            if ($cacheKey !== null && $response->status === 200 && str_contains((string) $response->header('Content-Type'), 'text/html')) {
                $this->pageCache->put($cacheKey, $response->body, Csp::nonce());
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
        (new AdminController($this->db, $this->auth, $this->settings, $this->pluginStatuses, $this->adminPages))->routes($router);
        (new PasswordResetController($this->db, $this->auth, $this->settings, $this->mailer, $this->events, $this->adminPages))->routes($router);
        (new OAuthController($this->db, $this->auth, $this->settings, $this->oauthProviders, null, $this->adminPages))->routes($router);
        (new CollectionsController($this->db, $this->auth, $this->settings, $this->fieldTypes, $this->adminPages))->routes($router);
        (new EntriesController($this->db, $this->auth, $this->settings, $this->fieldTypes, $this->events, $this->adminPages))->routes($router);
        (new MediaController($this->db, $this->auth, $this->settings, $this->adminPages))->routes($router);
        (new UsersController($this->db, $this->auth, $this->settings, $this->adminPages, $this->mailer, $this->events))->routes($router);
        (new RolesController($this->db, $this->auth, $this->settings, $this->adminPages, $this->capabilities))->routes($router);
        (new TokensController($this->db, $this->auth, $this->settings, $this->adminPages))->routes($router);
        (new SettingsController($this->db, $this->auth, $this->settings, $this->adminPages))->routes($router);
        (new MenusController($this->db, $this->auth, $this->settings, $this->events, $this->adminPages))->routes($router);
        // Plugin admin pages, after the core admin controllers so a plugin slug
        // can never shadow a core /admin route.
        (new PluginPagesController($this->db, $this->auth, $this->settings, $this->adminPages))->routes($router);
        (new ApiController($this->db, $this->fieldTypes, $this->apiAuth, $this->events, $this->apiFlood, $this->settings, $this->skills, $this->mcpToolsets))->routes($router);

        // Plugin public routes (ADR 0017), mounted after every core surface — so a
        // plugin can never shadow /admin or /api — and before the content catch-all
        // below, so `/ext/{namespace}/…` resolves to the plugin, not to content.
        foreach ($this->pluginRoutes->all() as $route) {
            $handler = $route['handler'];
            match ($route['method']) {
                'GET'    => $router->get($route['pattern'], $handler),
                'POST'   => $router->post($route['pattern'], $handler),
                'PUT'    => $router->put($route['pattern'], $handler),
                'PATCH'  => $router->patch($route['pattern'], $handler),
                'DELETE' => $router->delete($route['pattern'], $handler),
                default  => throw new \LogicException("Unsupported plugin route method \"{$route['method']}\"."),
            };
        }

        // Registered last: the public site owns `/` and its {collection} routes
        // match only after every literal /admin and /api route has had its turn,
        // so they can never shadow the application's own surfaces.
        (new SiteController($this->db, $this->fieldTypes, Config::home(), null, $this->headContributors, $this->settings, $this->pageSections))->routes($router);
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
     * a non-GET request, an admin / API / theme-asset path, a draft **preview**,
     * or caching disabled. Only the `page` query param varies a public page, so
     * nothing else is keyed.
     */
    private function cacheKey(Request $request): ?string
    {
        if ($request->method !== 'GET' || !$this->pageCache->enabled()) {
            return null;
        }
        // A draft preview (?preview=<token>, ADR 0021) must NEVER be cached: the
        // key ignores unknown params, so a preview 200 would otherwise be stored
        // under the public URL and served to everyone. Bail before keying.
        if ($request->query('preview') !== null) {
            return null;
        }
        foreach (['/admin', '/api', '/theme/assets'] as $prefix) {
            if ($request->path === $prefix || str_starts_with($request->path, $prefix . '/')) {
                return null;
            }
        }
        // Plugin page sections (ADR 0023) vary by their own query params (a search,
        // a category, a sort) that cacheKey does not — and does not model — so
        // caching one under path+page would poison it (one search served for
        // another) and fill the cache. Never page-cache a section path in v1; a
        // query-aware section cache is a tracked follow-up.
        $first = explode('/', ltrim($request->path, '/'))[0] ?? '';
        if ($first !== '' && $this->pageSections->has($first)) {
            return null;
        }
        $page = (int) ($request->query('page') ?? 1);
        // Never mint a cache entry for an absurd page number: cacheKey appends
        // ?page=N for ANY cached GET (home and entry pages ignore the param and
        // 200 too), so without a ceiling an anonymous client could fill the cache
        // dir one file per N (SVM-1). Past the ceiling → uncached (render, never
        // store); the collection index additionally 404s a page past its end.
        if ($page > self::MAX_CACHEABLE_PAGE) {
            return null;
        }
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

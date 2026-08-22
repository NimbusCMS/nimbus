<?php

declare(strict_types=1);

namespace Nimbus\Api;

use Nimbus\Auth\RoleRepository;
use Nimbus\Auth\UserRepository;
use Nimbus\Content\CollectionRepository;
use Nimbus\Content\CollectionService;
use Nimbus\Content\FieldTypeRegistry;
use Nimbus\Database\Connection;
use Nimbus\Http\ApiRateLimiter;
use Nimbus\Http\Middleware\ApiAuthMiddleware;
use Nimbus\Http\Middleware\RateLimitMiddleware;
use Nimbus\Http\Request;
use Nimbus\Http\Response;
use Nimbus\Http\Router;
use Nimbus\Mcp\ContentToolset;
use Nimbus\Mcp\McpServer;
use Nimbus\Mcp\MediaToolset;
use Nimbus\Mcp\SchemaToolset;
use Nimbus\Mcp\SettingsToolset;
use Nimbus\Mcp\TokensToolset;
use Nimbus\Mcp\UsersToolset;
use Nimbus\Media\MediaRepository;
use Nimbus\Media\MediaService;
use Nimbus\Media\MediaUploader;
use Nimbus\Media\MediaUsageRepository;
use Nimbus\Settings\Settings;
use Nimbus\Settings\SettingsRegistry;
use Nimbus\Settings\SettingsRepository;
use Nimbus\Support\Config;
use Nimbus\Support\EventDispatcher;

/**
 * The headless API, v1 — the HTTP transport in front of {@see EntryOperations}.
 *
 * All content logic (deny-by-default authz, mass-assignment-safe binding,
 * optimistic concurrency, auditing) lives in the shared service (ADR 0009); this
 * controller only speaks HTTP: it authenticates via middleware, decodes the
 * request (query pagination, JSON body, `If-Match`), calls the service, and maps
 * the {@see EntryOpResult} back to status codes, ETags and a Location. MCP calls
 * the same service, so the two transports can never diverge on the rules.
 */
final class ApiController
{
    private CollectionRepository $collections;
    private FieldTypeRegistry $types;
    private EntryOperations $ops;
    private Settings $settings;
    private McpServer $mcpServer;
    private ApiAuthMiddleware $auth;
    private ApiAuthContext $authContext;
    private RateLimitMiddleware $ipFlood;
    private RateLimitMiddleware $tokenQuota;

    public function __construct(
        Connection $db,
        FieldTypeRegistry $types,
        ApiAuthContext $authContext,
        EventDispatcher $events,
    ) {
        $this->collections = new CollectionRepository($db);
        $this->types       = $types;
        $this->ops         = new EntryOperations($db, $types, $events);
        // Toolsets are ordered management-first so a fixed management name is
        // claimed before a content verb could parse it.
        $mediaRepo  = new MediaRepository($db);
        $mediaUsage = new MediaUsageRepository($db);
        // MCP uploads are base64, not HTTP file uploads, so a copy mover replaces
        // the default is_uploaded_file/move_uploaded_file — the uploader still
        // sniffs + allow-lists the bytes.
        $uploader = new MediaUploader($mediaRepo, Config::uploadPath(), Config::uploadUrl(), Config::uploadMaxBytes(), static fn (string $from, string $to): bool => copy($from, $to));
        $settingsRegistry = new SettingsRegistry($this->collections);
        $settings         = new Settings(new SettingsRepository($db), $settingsRegistry);
        $this->settings   = $settings;
        $this->mcpServer = new McpServer(
            new SchemaToolset($this->collections, new CollectionService($db, $this->collections), $types, $events),
            new MediaToolset($mediaRepo, $uploader, new MediaService($mediaRepo, $mediaUsage, Config::basePath()), $mediaUsage, $events),
            new UsersToolset(new UserRepository($db), $events),
            new TokensToolset(new ApiTokenRepository($db), new RoleRepository($db), $events),
            new SettingsToolset($settings, $settingsRegistry, $events),
            new ContentToolset($this->collections, $types, $this->ops),
        );
        $this->authContext = $authContext;
        $this->auth        = new ApiAuthMiddleware(new ApiTokenRepository($db), $authContext, $events);

        $limiter = new ApiRateLimiter($db);
        $window  = Config::apiRateWindow();
        // Before auth: a per-IP flood guard — catches no-token / invalid-token
        // floods (each a different bogus token, bucketable only by IP).
        $this->ipFlood = new RateLimitMiddleware($limiter, Config::apiFloodLimit(), $window, static fn (Request $req): string => 'ip:' . $req->ip());
        // After auth: a per-token quota, keyed by the principal the auth
        // middleware just established.
        $this->tokenQuota = new RateLimitMiddleware($limiter, Config::apiRateLimit(), $window, fn (Request $req): ?string => ($p = $authContext->principal()) !== null ? 'tok:' . $p->tokenId : null);
    }

    public function routes(Router $r): void
    {
        // Order matters: flood guard → authenticate → per-token quota → handler.
        $r->group('/api/v1', [$this->ipFlood, $this->auth, $this->tokenQuota], function (Router $g): void {
            $g->get('/openapi.json', fn (Request $req, array $p): Response => $this->openapi())->name('api.openapi');
            $g->post('/mcp', fn (Request $req, array $p): Response => $this->mcp($req))->name('api.mcp');
            $g->get('/collections/{handle}/entries', fn (Request $req, array $p): Response => $this->index($req, $p['handle']))->name('api.entries.index');
            $g->get('/collections/{handle}/entries/{slug}', fn (Request $req, array $p): Response => $this->show($req, $p['handle'], $p['slug']))->name('api.entries.show');
            $g->post('/collections/{handle}/entries', fn (Request $req, array $p): Response => $this->create($req, $p['handle']))->name('api.entries.create');
            $g->patch('/collections/{handle}/entries/{slug}', fn (Request $req, array $p): Response => $this->update($req, $p['handle'], $p['slug']));
            $g->delete('/collections/{handle}/entries/{slug}', fn (Request $req, array $p): Response => $this->destroy($req, $p['handle'], $p['slug']));
        });
    }

    /**
     * The generated OpenAPI document for this install (ADR 0008). Behind the
     * group's bearer auth — a contract for authenticated clients — and describes
     * the full model (a scope-filtered per-token spec is a later refinement).
     */
    private function openapi(): Response
    {
        return Response::json((new OpenApiGenerator($this->collections, $this->types, $this->settings->title()))->generate());
    }

    /**
     * The MCP endpoint (ADR 0009): JSON-RPC 2.0 over HTTP, inside the API group,
     * so it inherits bearer auth and rate limiting. It decodes the body, hands
     * the message to the shared {@see McpServer}, and returns the JSON-RPC reply
     * — or 202 Accepted with no body for a notification, which gets no response.
     */
    private function mcp(Request $request): Response
    {
        $principal = $this->authContext->principal();
        if ($principal === null) {
            return ApiResponse::unauthorized();
        }
        $reply = $this->mcpServer->handle($request->json(), $principal, $this->context($request));
        if ($reply === null) {
            return Response::file('', 'application/json; charset=UTF-8', 202);
        }
        return Response::json($reply);
    }

    /** A page of a collection's live entries, newest first. */
    private function index(Request $request, string $handle): Response
    {
        $principal = $this->principal();
        if ($principal instanceof Response) {
            return $principal;
        }
        $result = $this->ops->list($principal, $this->context($request), $handle, $this->page($request), $this->perPage($request));
        if (!$result->isOk()) {
            return $this->mapFailure($result);
        }
        return ApiResponse::ok($result->data ?? [], $result->meta);
    }

    /** A single live entry by slug. */
    private function show(Request $request, string $handle, string $slug): Response
    {
        $principal = $this->principal();
        if ($principal instanceof Response) {
            return $principal;
        }
        $result = $this->ops->get($principal, $this->context($request), $handle, $slug);
        if (!$result->isOk()) {
            return $this->mapFailure($result);
        }
        // The ETag lets a client cache and edit safely (If-Match).
        return ApiResponse::ok($result->data ?? [])
            ->withHeader('ETag', EntryETag::of((int) $result->entryId, (int) $result->version));
    }

    // ------------------------------------------------------------------ writes

    /** Create an entry. Requires `{handle}:write`. → 201 with the entry, a Location, and its ETag. */
    private function create(Request $request, string $handle): Response
    {
        $principal = $this->principal();
        if ($principal instanceof Response) {
            return $principal;
        }
        $result = $this->ops->create($principal, $this->context($request), $handle, $request->json());
        if (!$result->isOk()) {
            return $this->mapFailure($result);
        }
        return $this->entityResponse($handle, $result, 201);
    }

    /** Update an entry by slug. Requires `{handle}:write` and a matching If-Match. → 200. */
    private function update(Request $request, string $handle, string $slug): Response
    {
        $principal = $this->principal();
        if ($principal instanceof Response) {
            return $principal;
        }
        $result = $this->ops->update($principal, $this->context($request), $handle, $slug, $request->json(), $this->precondition($request));
        if (!$result->isOk()) {
            return $this->mapFailure($result);
        }
        return $this->entityResponse($handle, $result, 200);
    }

    /** Delete an entry by slug. Requires `{handle}:write` and a matching If-Match. → 204. */
    private function destroy(Request $request, string $handle, string $slug): Response
    {
        $principal = $this->principal();
        if ($principal instanceof Response) {
            return $principal;
        }
        $result = $this->ops->delete($principal, $this->context($request), $handle, $slug, $this->precondition($request));
        if (!$result->isOk()) {
            return $this->mapFailure($result);
        }
        return ApiResponse::noContent();
    }

    // --------------------------------------------------------------- transport

    /** The authenticated principal, or a 401 (the auth middleware normally guarantees one). */
    private function principal(): TokenPrincipal|Response
    {
        return $this->authContext->principal() ?? ApiResponse::unauthorized();
    }

    /** The audit context for this request: the client IP and the path it hit. */
    private function context(Request $request): EntryOpContext
    {
        return new EntryOpContext($request->ip(), $request->path);
    }

    /** The write's concurrency precondition, carried by HTTP as `If-Match`. */
    private function precondition(Request $request): Precondition
    {
        return Precondition::ifMatch($request->header('If-Match'));
    }

    /** Map a failed content operation to its HTTP status and error envelope. */
    private function mapFailure(EntryOpResult $result): Response
    {
        return match ($result->status) {
            EntryOpStatus::Forbidden            => ApiResponse::forbidden($result->message),
            EntryOpStatus::NotFound             => ApiResponse::notFound($result->message),
            EntryOpStatus::Invalid              => ApiResponse::invalid($result->errors, $result->code, $result->message),
            EntryOpStatus::PreconditionRequired => ApiResponse::error(428, 'precondition_required', $result->message),
            EntryOpStatus::PreconditionFailed   => ApiResponse::error(412, 'precondition_failed', $result->message),
            EntryOpStatus::Ok                   => throw new \LogicException('mapFailure called on a successful result.'),
        };
    }

    /**
     * The success body for a write: the saved entry with its fresh ETag (plus a
     * Location on create).
     */
    private function entityResponse(string $handle, EntryOpResult $result, int $status): Response
    {
        $data     = $result->data ?? [];
        $response = ApiResponse::entity($data, $status)
            ->withHeader('ETag', EntryETag::of((int) $result->entryId, (int) $result->version));

        if ($status === 201) {
            $slug     = (string) ($data['slug'] ?? '');
            $response = $response->withHeader('Location', "/api/v1/collections/{$handle}/entries/{$slug}");
        }
        return $response;
    }

    private function perPage(Request $request): int
    {
        // 0 lets the service apply its default; it also clamps the ceiling.
        return (int) ($request->query('per_page') ?? 0);
    }

    private function page(Request $request): int
    {
        return (int) ($request->query('page') ?? 1);
    }
}

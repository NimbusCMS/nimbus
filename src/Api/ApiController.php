<?php

declare(strict_types=1);

namespace Nimbus\Api;

use Nimbus\Application;
use Nimbus\Content\CollectionRepository;
use Nimbus\Content\FieldTypeRegistry;
use Nimbus\Database\Connection;
use Nimbus\Http\ApiRateLimiter;
use Nimbus\Http\Middleware\ApiAuthMiddleware;
use Nimbus\Http\Middleware\RateLimitMiddleware;
use Nimbus\Http\Request;
use Nimbus\Http\Response;
use Nimbus\Http\Router;
use Nimbus\Mcp\Guide\SkillRegistry;
use Nimbus\Mcp\McpServer;
use Nimbus\Mcp\McpServerFactory;
use Nimbus\Settings\Settings;
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

    /** @param RateLimitMiddleware $ipFlood the per-IP flood guard, built once by
     *  the kernel and shared with the CORS preflight so both count one `ip:` bucket. */
    public function __construct(
        Connection $db,
        FieldTypeRegistry $types,
        ApiAuthContext $authContext,
        EventDispatcher $events,
        RateLimitMiddleware $ipFlood,
        Settings $settings,
        SkillRegistry $skills,
    ) {
        $this->collections = new CollectionRepository($db);
        $this->types       = $types;
        $this->ops         = new EntryOperations($db, $types, $events);
        $this->settings    = $settings;
        // One assembly seam for both transports (ADR 0013) — the toolset list,
        // the agent guide and the server version live in the factory, so the HTTP
        // and stdio front doors can never drift.
        $this->mcpServer = McpServerFactory::build($db, $types, $events, $settings, $this->ops, $skills, Application::VERSION, Config::basePath());
        $this->authContext = $authContext;
        $this->auth        = new ApiAuthMiddleware(new ApiTokenRepository($db), $authContext, $events);

        $limiter = new ApiRateLimiter($db);
        $window  = Config::apiRateWindow();
        // Before auth: the per-IP flood guard — built by the kernel and shared with
        // the CORS preflight (HTTP-4) so both count into the same `ip:` bucket.
        $this->ipFlood = $ipFlood;
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
     * The generated OpenAPI document for this install (ADR 0008, amended by
     * Slice B). **Scoped to the presenting token** — it describes only the
     * collections the token can read (write ops only where it can write) — so the
     * spec can't enumerate what the endpoints hide. Fail-closed: it resolves the
     * principal through the 401-guarding {@see principal()} helper and never calls
     * the full-document path (that belongs to `nimbus openapi` alone).
     */
    private function openapi(): Response
    {
        $principal = $this->principal();
        if ($principal instanceof Response) {
            return $principal; // 401 — never emit a document without a principal
        }
        return Response::json((new OpenApiGenerator($this->collections, $this->types, $this->settings->title()))->generateFor($principal));
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

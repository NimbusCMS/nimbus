<?php

declare(strict_types=1);

namespace Nimbus\Api;

use Nimbus\Content\Collection;
use Nimbus\Content\CollectionRepository;
use Nimbus\Content\EntryInput;
use Nimbus\Content\EntryRepository;
use Nimbus\Content\EntryService;
use Nimbus\Content\EntryView;
use Nimbus\Content\FieldTypeRegistry;
use Nimbus\Content\Publication;
use Nimbus\Content\RelationRepository;
use Nimbus\Database\Connection;
use Nimbus\Http\ApiRateLimiter;
use Nimbus\Http\Middleware\ApiAuthMiddleware;
use Nimbus\Http\Middleware\RateLimitMiddleware;
use Nimbus\Http\Request;
use Nimbus\Http\Response;
use Nimbus\Http\Router;
use Nimbus\Media\MediaRepository;
use Nimbus\Support\Config;
use Nimbus\Support\CoreEvents;
use Nimbus\Support\EventDispatcher;

/**
 * The headless API, v1.
 *
 * Reads serve exactly the *live* set defined in Publication (a draft or
 * not-yet-due scheduled entry can never be read). Writes (ADR 0007) are a new
 * transport in front of the same `EntryService` the admin uses — so validation,
 * slugs, the transaction, events, and the allow-list field binding that guards
 * mass-assignment are reused, never reimplemented.
 *
 * Auth is a bearer token; every scope is checked deny-by-default before the
 * entry is looked up. Values serialize through each field type's toApi(), so the
 * wire format is the field types' contract, not the storage layout.
 */
final class ApiController
{
    /** The largest page a client may request; keeps a single response bounded. */
    private const MAX_PER_PAGE = 50;
    private const DEFAULT_PER_PAGE = 20;

    private CollectionRepository $collections;
    private EntryRepository $entries;
    private RelationRepository $relations;
    private EntryView $view;
    private EntryService $entryService;
    private ApiAuthMiddleware $auth;
    private ApiAuthContext $authContext;
    private EventDispatcher $events;
    private RateLimitMiddleware $ipFlood;
    private RateLimitMiddleware $tokenQuota;

    public function __construct(
        Connection $db,
        private FieldTypeRegistry $types,
        ApiAuthContext $authContext,
        EventDispatcher $events,
    ) {
        $this->collections   = new CollectionRepository($db);
        $this->entries       = new EntryRepository($db);
        $this->relations     = new RelationRepository($db);
        $this->view          = new EntryView($types, $this->relations, new MediaRepository($db));
        $this->entryService  = new EntryService($db, $this->entries, $this->relations, $types, $events);
        $this->authContext   = $authContext;
        $this->events        = $events;
        $this->auth          = new ApiAuthMiddleware(new ApiTokenRepository($db), $authContext, $events);

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
        return Response::json((new OpenApiGenerator($this->collections, $this->types))->generate());
    }

    /** A page of a collection's live entries, newest first. */
    private function index(Request $request, string $handle): Response
    {
        $principal = $this->authorize($request, $handle);
        if ($principal instanceof Response) {
            return $principal;
        }

        $collection = $this->collections->findByHandle($handle);
        if ($collection === null) {
            return ApiResponse::notFound("No collection with handle \"{$handle}\".");
        }

        $perPage = $this->perPage($request);
        $page    = $this->page($request);
        $total   = $this->entries->countLive($collection->id);
        $rows    = $this->entries->liveForCollection($collection->id, $perPage, ($page - 1) * $perPage);

        return ApiResponse::ok(
            $this->view->many($collection, $rows, $this->scopeFilter($principal)),
            [
                'page'       => $page,
                'per_page'   => $perPage,
                'total'      => $total,
                'total_pages' => $total === 0 ? 0 : (int) ceil($total / $perPage),
            ],
        );
    }

    /** A single live entry by slug. */
    private function show(Request $request, string $handle, string $slug): Response
    {
        $principal = $this->authorize($request, $handle);
        if ($principal instanceof Response) {
            return $principal;
        }

        $collection = $this->collections->findByHandle($handle);
        if ($collection === null) {
            return ApiResponse::notFound("No collection with handle \"{$handle}\".");
        }

        $row = $this->entries->findLiveBySlug($collection->id, $slug);
        if ($row === null) {
            // A draft, a scheduled-but-not-due, or a genuinely absent entry all
            // look the same from outside — nothing to distinguish leaks.
            return ApiResponse::notFound("No published entry \"{$slug}\" in \"{$handle}\".");
        }

        // The ETag lets a client cache and edit safely (If-Match).
        return ApiResponse::ok($this->view->one($collection, $row, $this->scopeFilter($principal)))
            ->withHeader('ETag', EntryETag::of((int) $row['id'], (int) $row['version']));
    }

    // ------------------------------------------------------------------ writes

    /** Create an entry. Requires `{handle}:write`. → 201 with the entry, a Location, and its ETag. */
    private function create(Request $request, string $handle): Response
    {
        $principal = $this->authorize($request, $handle, 'write');
        if ($principal instanceof Response) {
            return $principal;
        }
        $collection = $this->collections->findByHandle($handle);
        if ($collection === null) {
            return ApiResponse::notFound("No collection with handle \"{$handle}\".");
        }

        $result = $this->entryService->save($collection, $this->inputFrom($request, $collection, null), null, null);
        if (!$result->successful) {
            return ApiResponse::invalid($result->errors);
        }

        $row = $this->entries->find($collection->id, (int) $result->entryId) ?? [];
        $this->announceWrite($request, $principal, $collection, 'create', $row);
        return $this->entityResponse($collection, $row, 201, $principal);
    }

    /** Update an entry by slug. Requires `{handle}:write` and a matching If-Match. → 200. */
    private function update(Request $request, string $handle, string $slug): Response
    {
        $principal = $this->authorize($request, $handle, 'write');
        if ($principal instanceof Response) {
            return $principal;
        }
        $collection = $this->collections->findByHandle($handle);
        if ($collection === null) {
            return ApiResponse::notFound("No collection with handle \"{$handle}\".");
        }
        $row = $this->entries->findBySlug($collection->id, $slug);
        if ($row === null) {
            return ApiResponse::notFound("No entry \"{$slug}\" in \"{$handle}\".");
        }

        $precondition = $this->requireIfMatch($request, (int) $row['id'], (int) $row['version']);
        if ($precondition !== null) {
            return $precondition;
        }

        $result = $this->entryService->save($collection, $this->inputFrom($request, $collection, $row), (int) $row['id'], null);
        if (!$result->successful) {
            return ApiResponse::invalid($result->errors);
        }

        $saved = $this->entries->find($collection->id, (int) $result->entryId) ?? [];
        $this->announceWrite($request, $principal, $collection, 'update', $saved);
        return $this->entityResponse($collection, $saved, 200, $principal);
    }

    /** Delete an entry by slug. Requires `{handle}:write` and a matching If-Match. → 204. */
    private function destroy(Request $request, string $handle, string $slug): Response
    {
        $principal = $this->authorize($request, $handle, 'write');
        if ($principal instanceof Response) {
            return $principal;
        }
        $collection = $this->collections->findByHandle($handle);
        if ($collection === null) {
            return ApiResponse::notFound("No collection with handle \"{$handle}\".");
        }
        $row = $this->entries->findBySlug($collection->id, $slug);
        if ($row === null) {
            return ApiResponse::notFound("No entry \"{$slug}\" in \"{$handle}\".");
        }

        $precondition = $this->requireIfMatch($request, (int) $row['id'], (int) $row['version']);
        if ($precondition !== null) {
            return $precondition;
        }

        $this->entryService->delete($collection, (int) $row['id']);
        $this->announceWrite($request, $principal, $collection, 'delete', $row);
        return ApiResponse::noContent();
    }

    /**
     * Announce a write to any audit listener — best-effort, isolated, and it names the token.
     *
     * @param array<string,mixed> $row the saved (or, for delete, just-removed) entry row
     */
    private function announceWrite(Request $request, TokenPrincipal $principal, Collection $collection, string $action, array $row): void
    {
        $this->events->emitBestEffort(CoreEvents::API_ENTRY_WRITTEN, [
            'token_id'   => $principal->tokenId,
            'token_name' => $principal->name,
            'collection' => $collection->handle,
            'entry_id'   => (int) ($row['id'] ?? 0),
            'slug'       => (string) ($row['slug'] ?? ''),
            'action'     => $action,
            'ip'         => $request->ip(),
            'path'       => $request->path,
            'at'         => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * The If-Match precondition for a write: null when it passes, or a 428/412.
     * Concurrency is mandatory — a client must have read the current version, so
     * two machine clients cannot silently overwrite each other.
     */
    private function requireIfMatch(Request $request, int $id, int $version): ?Response
    {
        $ifMatch = $request->header('If-Match');
        if ($ifMatch === null || trim($ifMatch) === '') {
            return ApiResponse::error(428, 'precondition_required', "This write needs an If-Match header carrying the entry's current ETag.");
        }
        if (!EntryETag::ifMatchSatisfied($ifMatch, EntryETag::of($id, $version))) {
            return ApiResponse::error(412, 'precondition_failed', 'The entry changed since you last read it; re-read it and retry.');
        }
        return null;
    }

    /**
     * Map the JSON body to an EntryInput. Only the collection's declared fields
     * are read — the allow-list that guards mass-assignment; unknown keys are
     * ignored. On update a field the body omits keeps its stored value (PATCH
     * semantics), and title/slug/status default to the stored entry.
     *
     * @param array<string,mixed>|null $existing the stored (hydrated) row on update
     */
    private function inputFrom(Request $request, Collection $collection, ?array $existing): EntryInput
    {
        $body   = $request->json();
        $fields = is_array($body['fields'] ?? null) ? $body['fields'] : [];
        $base   = $existing !== null ? $this->existingValues($collection, $existing) : [];

        $values = [];
        foreach ($collection->fields as $field) {
            $type = $this->types->forDisplay($field->type);
            $values[$field->handle] = array_key_exists($field->handle, $fields)
                ? $type->normalize($fields[$field->handle])
                : ($base[$field->handle] ?? $type->normalize(null));
        }

        $status = is_string($body['status'] ?? null) && Publication::isStoredStatus($body['status'])
            ? $body['status']
            : ($existing !== null ? (string) $existing['status'] : Publication::DRAFT);
        $title = array_key_exists('title', $body) ? trim((string) $body['title']) : ($existing !== null ? (string) $existing['title'] : '');
        $slug  = array_key_exists('slug', $body) ? trim((string) $body['slug']) : ($existing !== null ? (string) $existing['slug'] : '');
        $publishedAt = is_string($body['published_at'] ?? null) && trim($body['published_at']) !== '' ? trim($body['published_at']) : null;

        return new EntryInput($title, $slug, $status, $values, $publishedAt);
    }

    /**
     * The stored field values of an entry (for keep-omitted-on-PATCH) —
     * non-relation values from the JSON column, relations from their table.
     *
     * @param array<string,mixed> $row a hydrated entry row
     * @return array<string,mixed>
     */
    private function existingValues(Collection $collection, array $row): array
    {
        $data   = is_array($row['data'] ?? null) ? $row['data'] : [];
        $values = [];
        foreach ($collection->fields as $field) {
            $values[$field->handle] = $field->type === 'relation'
                ? $this->relations->targets((int) $row['id'], $field->id)
                : ($data[$field->handle] ?? null);
        }
        return $values;
    }

    /**
     * The success body for a write: the saved entry with its fresh ETag (plus a
     * Location on create).
     *
     * @param array<string,mixed> $row the saved (hydrated) entry row
     */
    private function entityResponse(Collection $collection, array $row, int $status, TokenPrincipal $principal): Response
    {
        $response = ApiResponse::entity($this->view->one($collection, $row, $this->scopeFilter($principal)), $status)
            ->withHeader('ETag', EntryETag::of((int) ($row['id'] ?? 0), (int) ($row['version'] ?? 1)));

        if ($status === 201) {
            $response = $response->withHeader('Location', "/api/v1/collections/{$collection->handle}/entries/" . (string) ($row['slug'] ?? ''));
        }
        return $response;
    }

    /**
     * Authorize the token for $action ('read'|'write') on $handle, returning the
     * principal to use — or a 403 Response to short-circuit.
     *
     * The scope check runs *before* collection/entry existence on purpose: a
     * token that may not act on a handle gets 403 whether or not that collection
     * exists, so it cannot enumerate what lies outside its scope by telling 403
     * from 404.
     */
    private function authorize(Request $request, string $handle, string $action = 'read'): TokenPrincipal|Response
    {
        $principal = $this->authContext->principal();
        if ($principal === null || !$principal->can($handle, $action)) {
            // A valid token refused by scope is worth auditing (a null principal
            // would be an auth failure, already announced by the middleware).
            if ($principal !== null) {
                $this->events->emitBestEffort(CoreEvents::API_ACCESS_DENIED, [
                    'token_id'   => $principal->tokenId,
                    'token_name' => $principal->name,
                    'resource'   => $handle,
                    'action'     => $action,
                    'ip'         => $request->ip(),
                    'path'       => $request->path,
                    'at'         => date('Y-m-d H:i:s'),
                ]);
            }
            return ApiResponse::forbidden("This token cannot {$action} \"{$handle}\".");
        }
        return $principal;
    }

    /** A scope predicate for EntryView: which related collections this token may expand. */
    private function scopeFilter(TokenPrincipal $principal): \Closure
    {
        return static fn (string $targetHandle): bool => $principal->can($targetHandle, 'read');
    }

    private function perPage(Request $request): int
    {
        $requested = (int) ($request->query('per_page') ?? self::DEFAULT_PER_PAGE);
        if ($requested < 1) {
            $requested = self::DEFAULT_PER_PAGE;
        }
        return min($requested, self::MAX_PER_PAGE);
    }

    private function page(Request $request): int
    {
        return max(1, (int) ($request->query('page') ?? 1));
    }
}

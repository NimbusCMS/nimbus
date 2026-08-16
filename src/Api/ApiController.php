<?php

declare(strict_types=1);

namespace Nimbus\Api;

use Nimbus\Content\CollectionRepository;
use Nimbus\Content\EntryRepository;
use Nimbus\Content\EntryView;
use Nimbus\Content\FieldTypeRegistry;
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
 * The read-only headless API, v1.
 *
 * Serves exactly the *live* set defined in Publication — a draft or a
 * not-yet-due scheduled entry can never be reached here, because the queries
 * (liveForCollection / findLiveBySlug) carry the same predicate the admin
 * badges use. Read-only on purpose: no writes over the API in this slice.
 *
 * Auth is a bearer token, applied to the whole group. Every value is serialized
 * through its field type's toApi(), so the wire format is the field types'
 * contract, not the storage layout.
 */
final class ApiController
{
    /** The largest page a client may request; keeps a single response bounded. */
    private const MAX_PER_PAGE = 50;
    private const DEFAULT_PER_PAGE = 20;

    private CollectionRepository $collections;
    private EntryRepository $entries;
    private EntryView $view;
    private ApiAuthMiddleware $auth;
    private ApiAuthContext $authContext;
    private EventDispatcher $events;
    private RateLimitMiddleware $ipFlood;
    private RateLimitMiddleware $tokenQuota;

    public function __construct(Connection $db, FieldTypeRegistry $types, ApiAuthContext $authContext, EventDispatcher $events)
    {
        $this->collections = new CollectionRepository($db);
        $this->entries     = new EntryRepository($db);
        $this->view        = new EntryView($types, new RelationRepository($db), new MediaRepository($db));
        $this->authContext = $authContext;
        $this->events      = $events;
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
            $g->get('/collections/{handle}/entries', fn (Request $req, array $p): Response => $this->index($req, $p['handle']))->name('api.entries.index');
            $g->get('/collections/{handle}/entries/{slug}', fn (Request $req, array $p): Response => $this->show($req, $p['handle'], $p['slug']))->name('api.entries.show');
        });
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

        // The ETag lets a client cache and, when writes land, edit safely (If-Match).
        return ApiResponse::ok($this->view->one($collection, $row, $this->scopeFilter($principal)))
            ->withHeader('ETag', EntryETag::of((int) $row['id'], (int) $row['version']));
    }

    /**
     * Authorize the token for reading $handle, returning the principal to use —
     * or a 403 Response to short-circuit.
     *
     * The scope check runs *before* collection existence on purpose: a token
     * that may not read a handle gets 403 whether or not that collection exists,
     * so a narrowly-scoped token cannot enumerate the collections outside its
     * scope by telling 403 from 404.
     */
    private function authorize(Request $request, string $handle): TokenPrincipal|Response
    {
        $principal = $this->authContext->principal();
        if ($principal === null || !$principal->can($handle, 'read')) {
            // A valid token refused by scope is worth auditing (a null principal
            // would be an auth failure, already announced by the middleware).
            if ($principal !== null) {
                $this->events->emitBestEffort(CoreEvents::API_ACCESS_DENIED, [
                    'token_id'   => $principal->tokenId,
                    'token_name' => $principal->name,
                    'resource'   => $handle,
                    'action'     => 'read',
                    'ip'         => $request->ip(),
                    'path'       => $request->path,
                    'at'         => date('Y-m-d H:i:s'),
                ]);
            }
            return ApiResponse::forbidden("This token cannot read \"{$handle}\".");
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

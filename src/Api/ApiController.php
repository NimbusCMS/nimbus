<?php

declare(strict_types=1);

namespace Nimbus\Api;

use Nimbus\Content\CollectionRepository;
use Nimbus\Content\EntryRepository;
use Nimbus\Content\EntryView;
use Nimbus\Content\FieldTypeRegistry;
use Nimbus\Content\RelationRepository;
use Nimbus\Database\Connection;
use Nimbus\Http\Middleware\ApiAuthMiddleware;
use Nimbus\Http\Request;
use Nimbus\Http\Response;
use Nimbus\Http\Router;
use Nimbus\Media\MediaRepository;

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

    public function __construct(Connection $db, FieldTypeRegistry $types, ApiAuthContext $authContext)
    {
        $this->collections = new CollectionRepository($db);
        $this->entries     = new EntryRepository($db);
        $this->view        = new EntryView($types, new RelationRepository($db), new MediaRepository($db));
        $this->auth        = new ApiAuthMiddleware(new ApiTokenRepository($db), $authContext);
    }

    public function routes(Router $r): void
    {
        $r->group('/api/v1', [$this->auth], function (Router $g): void {
            $g->get('/collections/{handle}/entries', fn (Request $req, array $p): Response => $this->index($req, $p['handle']))->name('api.entries.index');
            $g->get('/collections/{handle}/entries/{slug}', fn (Request $req, array $p): Response => $this->show($p['handle'], $p['slug']))->name('api.entries.show');
        });
    }

    /** A page of a collection's live entries, newest first. */
    private function index(Request $request, string $handle): Response
    {
        $collection = $this->collections->findByHandle($handle);
        if ($collection === null) {
            return ApiResponse::notFound("No collection with handle \"{$handle}\".");
        }

        $perPage = $this->perPage($request);
        $page    = $this->page($request);
        $total   = $this->entries->countLive($collection->id);
        $rows    = $this->entries->liveForCollection($collection->id, $perPage, ($page - 1) * $perPage);

        return ApiResponse::ok(
            $this->view->many($collection, $rows),
            [
                'page'       => $page,
                'per_page'   => $perPage,
                'total'      => $total,
                'total_pages' => $total === 0 ? 0 : (int) ceil($total / $perPage),
            ],
        );
    }

    /** A single live entry by slug. */
    private function show(string $handle, string $slug): Response
    {
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

        return ApiResponse::ok($this->view->one($collection, $row));
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

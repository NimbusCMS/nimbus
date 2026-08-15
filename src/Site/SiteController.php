<?php

declare(strict_types=1);

namespace Nimbus\Site;

use Nimbus\Content\CollectionRepository;
use Nimbus\Content\EntryRepository;
use Nimbus\Content\EntryView;
use Nimbus\Content\FieldTypeRegistry;
use Nimbus\Content\RelationRepository;
use Nimbus\Database\Connection;
use Nimbus\Http\Request;
use Nimbus\Http\Response;
use Nimbus\Http\Router;
use Nimbus\Media\MediaRepository;
use Nimbus\Support\Config;
use Nimbus\View\View;

/**
 * Server-rendered public site.
 *
 * Serves exactly the *live* set — the same predicate the read API and the admin
 * badges use — so a draft or a not-yet-due scheduled entry is indistinguishable
 * from absent, here as everywhere. Content is prepared by EntryView (the one
 * shape the API serializes too) and handed to the active theme's plain-PHP
 * templates; the theme renders, it never queries.
 *
 * Registered last in the kernel, so its `{collection}` routes can never shadow
 * /admin or /api — those literal routes match first.
 */
final class SiteController
{
    /** Entries per collection-index page. */
    private const PER_PAGE = 20;

    private CollectionRepository $collections;
    private EntryRepository $entries;
    private EntryView $view;
    private View $render;

    public function __construct(Connection $db, FieldTypeRegistry $types)
    {
        $this->collections = new CollectionRepository($db);
        $this->entries     = new EntryRepository($db);
        $this->view        = new EntryView($types, new RelationRepository($db), new MediaRepository($db));
        $this->render      = new View(Config::themePath(), ['appName' => Config::appName()]);
    }

    public function routes(Router $r): void
    {
        $r->get('/{collection}', fn (Request $req, array $p): Response => $this->index($req, $p['collection']))->name('site.collection');
        $r->get('/{collection}/{slug}', fn (Request $req, array $p): Response => $this->show($p['collection'], $p['slug']))->name('site.entry');
    }

    /** A collection's live entries, newest first. */
    private function index(Request $request, string $handle): Response
    {
        $collection = $this->collections->findByHandle($handle);
        if ($collection === null) {
            return $this->notFound();
        }

        $page    = max(1, (int) ($request->query('page') ?? 1));
        $total   = $this->entries->countLive($collection->id);
        $rows    = $this->entries->liveForCollection($collection->id, self::PER_PAGE, ($page - 1) * self::PER_PAGE);

        return Response::html($this->render->render('collection', [
            'title'       => $collection->name,
            'collection'  => ['handle' => $collection->handle, 'name' => $collection->name],
            'entries'     => $this->view->many($collection, $rows),
            'page'        => $page,
            'total_pages' => $total === 0 ? 0 : (int) ceil($total / self::PER_PAGE),
        ]));
    }

    /** A single live entry by slug. */
    private function show(string $handle, string $slug): Response
    {
        $collection = $this->collections->findByHandle($handle);
        if ($collection === null) {
            return $this->notFound();
        }

        $row = $this->entries->findLiveBySlug($collection->id, $slug);
        if ($row === null) {
            // A draft, a scheduled-but-not-due, or a genuinely absent entry all
            // look the same from outside — nothing to distinguish leaks.
            return $this->notFound();
        }

        return Response::html($this->render->render('entry', [
            'title'      => (string) $row['title'],
            'collection' => ['handle' => $collection->handle, 'name' => $collection->name],
            'entry'      => $this->view->one($collection, $row),
        ]));
    }

    /**
     * A minimal 404 — deliberately not themed, so a theme only has to provide
     * the two content templates. The requested path is never echoed back.
     */
    private function notFound(): Response
    {
        return Response::html(
            '<!doctype html><meta charset="utf-8"><title>Not found</title>'
            . '<p style="font-family:system-ui,sans-serif;max-width:40rem;margin:14vh auto;padding:0 1.5rem">'
            . 'Nothing lives here.</p>',
            404,
        );
    }
}

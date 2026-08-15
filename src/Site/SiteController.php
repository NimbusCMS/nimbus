<?php

declare(strict_types=1);

namespace Nimbus\Site;

use Nimbus\Content\Collection;
use Nimbus\Content\CollectionRepository;
use Nimbus\Content\EntryRepository;
use Nimbus\Content\EntryService;
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

    /** Handle of the collection rendered at `/`, or null for the placeholder. */
    private ?string $home;

    public function __construct(Connection $db, FieldTypeRegistry $types, ?string $home = null, ?string $themePath = null)
    {
        $this->collections = new CollectionRepository($db);
        $this->entries     = new EntryRepository($db);
        $this->view        = new EntryView($types, new RelationRepository($db), new MediaRepository($db));
        $this->render      = new View($themePath ?? Config::themePath(), ['appName' => Config::appName()]);
        $this->home        = $home;
    }

    public function routes(Router $r): void
    {
        $r->get('/', fn (Request $req, array $p): Response => $this->homePage())->name('site.home');
        $r->get('/{collection}', fn (Request $req, array $p): Response => $this->index($req, $p['collection']))->name('site.collection');
        $r->get('/{collection}/{slug}', fn (Request $req, array $p): Response => $this->show($p['collection'], $p['slug']))->name('site.entry');
    }

    /**
     * The site root. Renders the designated home collection: a single-kind
     * collection shows its one live entry, a regular collection its live index.
     * No home configured, an unknown handle, or a home whose single entry is not
     * live all fall through to the placeholder — a misconfiguration never 500s
     * and a draft home never leaks.
     */
    private function homePage(): Response
    {
        $collection = $this->home === null ? null : $this->collections->findByHandle($this->home);
        if ($collection === null) {
            return $this->placeholder();
        }

        if ($collection->isSingle()) {
            $row = $this->entries->findLiveBySlug($collection->id, EntryService::SINGLETON_SLUG);
            return $row === null ? $this->placeholder() : $this->renderEntry($collection, $row);
        }

        return $this->renderCollection($collection, 1);
    }

    /** A collection's live entries, newest first. */
    private function index(Request $request, string $handle): Response
    {
        $collection = $this->collections->findByHandle($handle);
        if ($collection === null) {
            return $this->notFound();
        }

        return $this->renderCollection($collection, max(1, (int) ($request->query('page') ?? 1)));
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

        return $this->renderEntry($collection, $row);
    }

    /** Render a collection's live entry index (paginated). */
    private function renderCollection(Collection $collection, int $page): Response
    {
        $total = $this->entries->countLive($collection->id);
        $rows  = $this->entries->liveForCollection($collection->id, self::PER_PAGE, ($page - 1) * self::PER_PAGE);

        return Response::html($this->render->render($this->specialize('collection', $collection->handle), [
            'title'       => $collection->name,
            'collection'  => ['handle' => $collection->handle, 'name' => $collection->name],
            'entries'     => $this->view->many($collection, $rows),
            'page'        => $page,
            'total_pages' => $total === 0 ? 0 : (int) ceil($total / self::PER_PAGE),
        ]));
    }

    /**
     * Render one live entry.
     *
     * @param array<string,mixed> $row a live nb_entries row
     */
    private function renderEntry(Collection $collection, array $row): Response
    {
        return Response::html($this->render->render($this->specialize('entry', $collection->handle), [
            'title'      => (string) $row['title'],
            'collection' => ['handle' => $collection->handle, 'name' => $collection->name],
            'entry'      => $this->view->one($collection, $row),
        ]));
    }

    /**
     * The most specific template the theme provides: `{base}-{handle}` when it
     * exists (e.g. `entry-posts`, or `entry-homepage` for a home page), else the
     * generic `{base}`. One rule serves both per-collection styling and a
     * home-specific template, with a guaranteed fallback.
     */
    private function specialize(string $base, string $handle): string
    {
        $specific = $base . '-' . $handle;
        return $this->render->exists($specific) ? $specific : $base;
    }

    /**
     * Not found. A theme may provide a `404` template (rendered in its layout);
     * otherwise a minimal built-in page. The requested path is never echoed back.
     */
    private function notFound(): Response
    {
        if ($this->render->exists('404')) {
            return Response::html($this->render->render('404', ['title' => 'Not found']), 404);
        }

        return Response::html(
            '<!doctype html><meta charset="utf-8"><title>Not found</title>'
            . '<p style="font-family:system-ui,sans-serif;max-width:40rem;margin:14vh auto;padding:0 1.5rem">'
            . 'Nothing lives here.</p>',
            404,
        );
    }

    /**
     * The site root before a home is chosen — deliberately un-themed, so a fresh
     * install renders something honest without any configuration or content.
     */
    private function placeholder(): Response
    {
        $name = View::e(Config::appName());
        return Response::html(
            '<!doctype html><meta charset="utf-8"><title>' . $name . '</title>'
            . '<div style="font-family:system-ui,sans-serif;max-width:40rem;margin:14vh auto;padding:0 1.5rem">'
            . '<h1 style="letter-spacing:-.02em">' . $name . '</h1>'
            . '<p style="color:#6b7280;line-height:1.6">No home page is configured yet. Point '
            . '<code>home</code> in <code>config/site.php</code> at a collection, or head to '
            . '<a href="/admin">/admin</a> to manage content.</p></div>',
        );
    }
}

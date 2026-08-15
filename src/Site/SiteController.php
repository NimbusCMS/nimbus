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

    /** Most reusable blocks loaded for a page — blocks are a handful, not a feed. */
    private const MAX_BLOCKS = 100;

    /** Per-collection cap on sitemap URLs; keeps a single sitemap bounded. */
    private const SITEMAP_MAX = 5000;

    /**
     * Extensions a theme may serve as static assets, mapped to their content
     * type. An allowlist, so a stray `.php` (or anything executable) in a theme
     * is never handed out as source or run.
     */
    private const ASSET_TYPES = [
        'css'   => 'text/css; charset=UTF-8',
        'js'    => 'text/javascript; charset=UTF-8',
        'mjs'   => 'text/javascript; charset=UTF-8',
        'map'   => 'application/json; charset=UTF-8',
        'svg'   => 'image/svg+xml',
        'png'   => 'image/png',
        'jpg'   => 'image/jpeg',
        'jpeg'  => 'image/jpeg',
        'gif'   => 'image/gif',
        'webp'  => 'image/webp',
        'avif'  => 'image/avif',
        'ico'   => 'image/x-icon',
        'woff'  => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf'   => 'font/ttf',
        'txt'   => 'text/plain; charset=UTF-8',
    ];

    private CollectionRepository $collections;
    private EntryRepository $entries;
    private EntryView $view;
    private View $render;

    /** Absolute path to the active theme directory (for serving its assets). */
    private string $themeDir;

    /** Handle of the collection rendered at `/`, or null for the placeholder. */
    private ?string $home;

    /** @var array<string,array<string,mixed>>|null memoized live blocks by slug */
    private ?array $blocks = null;

    public function __construct(Connection $db, FieldTypeRegistry $types, ?string $home = null, ?string $themePath = null)
    {
        $this->collections = new CollectionRepository($db);
        $this->entries     = new EntryRepository($db);
        $this->view        = new EntryView($types, new RelationRepository($db), new MediaRepository($db));
        $this->themeDir    = $themePath ?? Config::themePath();
        $this->render      = new View($this->themeDir, [
            'appName' => Config::appName(),
            'menus'   => Config::menus(),
        ]);
        $this->home        = $home;
    }

    public function routes(Router $r): void
    {
        // Registered first among the site routes: literal, specific paths that
        // must resolve before the {collection} catch-alls ever see them.
        $r->get('/theme/assets/{path*}', fn (Request $req, array $p): Response => $this->asset($p['path']))->name('site.asset');
        $r->get('/sitemap.xml', fn (Request $req, array $p): Response => $this->sitemap())->name('site.sitemap');
        $r->get('/', fn (Request $req, array $p): Response => $this->homePage($req))->name('site.home');
        $r->get('/{collection}', fn (Request $req, array $p): Response => $this->index($req, $p['collection']))->name('site.collection');
        $r->get('/{collection}/{slug}', fn (Request $req, array $p): Response => $this->show($req, $p['collection'], $p['slug']))->name('site.entry');
    }

    /**
     * Serve a file from the active theme's `assets/` directory.
     *
     * The path comes from the URL, so it is resolved with realpath() and
     * confirmed to sit inside the assets directory — a `..` or an absolute path
     * escapes to nothing (404), never to a file elsewhere on disk. Only
     * allowlisted extensions are served, so a theme's PHP is never disclosed.
     */
    private function asset(string $path): Response
    {
        $base = realpath($this->themeDir . '/assets');
        if ($base === false) {
            return $this->assetNotFound();
        }

        $full = realpath($base . '/' . $path);
        if ($full === false || !is_file($full) || !str_starts_with($full, $base . DIRECTORY_SEPARATOR)) {
            return $this->assetNotFound();
        }

        $type = self::ASSET_TYPES[strtolower(pathinfo($full, PATHINFO_EXTENSION))] ?? null;
        if ($type === null) {
            return $this->assetNotFound();
        }

        return Response::file((string) file_get_contents($full), $type)
            ->withHeader('Cache-Control', 'public, max-age=3600');
    }

    private function assetNotFound(): Response
    {
        return Response::file('Asset not found', 'text/plain; charset=UTF-8', 404);
    }

    /**
     * An XML sitemap of the public site: the home page, each browsable
     * collection's index, and its live entries. Single-entry collections (a
     * Homepage, Settings) and the `blocks` fragments are left out — they are not
     * standalone pages to crawl. URLs are absolute, built from APP_URL.
     */
    private function sitemap(): Response
    {
        $base = Config::appUrl();
        $urls = ['<url><loc>' . $this->xml($base . '/') . '</loc></url>'];

        foreach ($this->collections->all() as $collection) {
            if ($collection->handle === 'blocks' || $collection->isSingle()) {
                continue;
            }
            $urls[] = '<url><loc>' . $this->xml($base . '/' . $collection->handle) . '</loc></url>';
            foreach ($this->entries->liveForCollection($collection->id, self::SITEMAP_MAX, 0) as $row) {
                $loc = $base . '/' . $collection->handle . '/' . (string) $row['slug'];
                $urls[] = '<url><loc>' . $this->xml($loc) . '</loc>' . $this->lastmod($row['published_at'] ?? null) . '</url>';
            }
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n"
            . implode("\n", $urls) . "\n"
            . '</urlset>' . "\n";

        return Response::file($xml, 'application/xml; charset=UTF-8');
    }

    /** A `<lastmod>` element for a stored timestamp, or empty when there is none. */
    private function lastmod(mixed $stored): string
    {
        if (!is_string($stored) || $stored === '') {
            return '';
        }
        return '<lastmod>' . (new \DateTimeImmutable($stored))->format('Y-m-d') . '</lastmod>';
    }

    private function xml(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    /**
     * The site root. Renders the designated home collection: a single-kind
     * collection shows its one live entry, a regular collection its live index.
     * No home configured, an unknown handle, or a home whose single entry is not
     * live all fall through to the placeholder — a misconfiguration never 500s
     * and a draft home never leaks.
     */
    private function homePage(Request $request): Response
    {
        $collection = $this->home === null ? null : $this->collections->findByHandle($this->home);
        if ($collection === null) {
            return $this->placeholder();
        }

        if ($collection->isSingle()) {
            $row = $this->entries->findLiveBySlug($collection->id, EntryService::SINGLETON_SLUG);
            return $row === null ? $this->placeholder() : $this->renderEntry($request, $collection, $row);
        }

        return $this->renderCollection($request, $collection, 1);
    }

    /** A collection's live entries, newest first. */
    private function index(Request $request, string $handle): Response
    {
        $collection = $this->collections->findByHandle($handle);
        if ($collection === null) {
            return $this->notFound();
        }

        return $this->renderCollection($request, $collection, max(1, (int) ($request->query('page') ?? 1)));
    }

    /** A single live entry by slug. */
    private function show(Request $request, string $handle, string $slug): Response
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

        return $this->renderEntry($request, $collection, $row);
    }

    /** Render a collection's live entry index (paginated). */
    private function renderCollection(Request $request, Collection $collection, int $page): Response
    {
        $total = $this->entries->countLive($collection->id);
        $rows  = $this->entries->liveForCollection($collection->id, self::PER_PAGE, ($page - 1) * self::PER_PAGE);

        return $this->renderPage($this->specialize('collection', $collection->handle), [
            'title'       => $collection->name,
            'meta'        => $this->meta($request->path, $collection->name, $this->describe(null, $collection), 'website'),
            'collection'  => ['handle' => $collection->handle, 'name' => $collection->name],
            'entries'     => $this->view->many($collection, $rows),
            'page'        => $page,
            'total_pages' => $total === 0 ? 0 : (int) ceil($total / self::PER_PAGE),
        ]);
    }

    /**
     * Render one live entry.
     *
     * @param array<string,mixed> $row a live nb_entries row
     */
    private function renderEntry(Request $request, Collection $collection, array $row): Response
    {
        $entry = $this->view->one($collection, $row);

        return $this->renderPage($this->specialize('entry', $collection->handle), [
            'title'      => (string) $row['title'],
            'meta'       => $this->meta($request->path, (string) $row['title'], $this->describe($entry, $collection), 'article'),
            'collection' => ['handle' => $collection->handle, 'name' => $collection->name],
            'entry'      => $entry,
        ]);
    }

    /**
     * The page's meta: what the theme puts in <head> — a canonical URL built from
     * APP_URL and this path, plus the description and Open Graph type.
     *
     * @return array{title:string,description:string,canonical:string,og_type:string}
     */
    private function meta(string $path, string $title, string $description, string $ogType): array
    {
        return [
            'title'       => $title,
            'description' => $description,
            'canonical'   => Config::appUrl() . $path,
            'og_type'     => $ogType,
        ];
    }

    /**
     * A meta description for a page: an entry's own `excerpt`/`summary`/
     * `description` field if it has one, else the collection's description, else
     * the site default. Clipped to a sensible length, tags and runs of space gone.
     *
     * @param array<string,mixed>|null $entry an EntryView for an entry page, or null for an index
     */
    private function describe(?array $entry, Collection $collection): string
    {
        $fields = is_array($entry['fields'] ?? null) ? $entry['fields'] : [];
        foreach (['excerpt', 'summary', 'description'] as $handle) {
            $value = $fields[$handle] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return $this->clip($value);
            }
        }
        if ($collection->description !== '') {
            return $this->clip($collection->description);
        }
        return Config::siteDescription();
    }

    /** Flatten to a single line and cap at a meta-description-friendly length. */
    private function clip(string $text): string
    {
        $text = trim((string) preg_replace('/\s+/', ' ', strip_tags($text)));
        return mb_strlen($text) > 160 ? mb_substr($text, 0, 157) . '…' : $text;
    }

    /**
     * Render a theme template into a full page, with the shared reusable blocks
     * always available to it (and its layout). One place adds `blocks`, so every
     * page — entry, index, themed 404 — can render them without repeating.
     *
     * @param array<string,mixed> $data
     */
    private function renderPage(string $template, array $data, int $status = 200): Response
    {
        $data['blocks'] = $this->blocks();
        return Response::html($this->render->render($template, $data), $status);
    }

    /**
     * Live entries of the conventional `blocks` collection, keyed by slug —
     * editor-defined content fragments a theme renders anywhere (an
     * announcement, a CTA, a colophon). Loaded lazily and once per request, so
     * nothing here runs for admin/API requests or when no `blocks` collection
     * exists; only the live set is exposed, like everything on the public site.
     *
     * @return array<string,array<string,mixed>>
     */
    private function blocks(): array
    {
        if ($this->blocks !== null) {
            return $this->blocks;
        }

        $this->blocks = [];
        $collection = $this->collections->findByHandle('blocks');
        if ($collection !== null) {
            foreach ($this->entries->liveForCollection($collection->id, self::MAX_BLOCKS, 0) as $row) {
                $this->blocks[(string) $row['slug']] = $this->view->one($collection, $row);
            }
        }
        return $this->blocks;
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
            return $this->renderPage('404', ['title' => 'Not found'], 404);
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

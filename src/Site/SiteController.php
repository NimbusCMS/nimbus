<?php

declare(strict_types=1);

namespace Nimbus\Site;

use Nimbus\Content\Collection;
use Nimbus\Content\CollectionRepository;
use Nimbus\Content\EntryRepository;
use Nimbus\Content\EntryService;
use Nimbus\Content\EntryView;
use Nimbus\Content\FieldTypeRegistry;
use Nimbus\Content\PreviewTokens;
use Nimbus\Content\RelationRepository;
use Nimbus\Database\Connection;
use Nimbus\Http\Csp;
use Nimbus\Http\Request;
use Nimbus\Http\Response;
use Nimbus\Http\Router;
use Nimbus\Media\MediaRepository;
use Nimbus\Settings\Settings;
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

    /** Live entries a theme may render as collection navigation (a docs sidebar):
     *  a bounded, curated set, not a feed. Past this the nav truncates in index
     *  order — document it like SITEMAP_MAX; nav is for curated collections. */
    private const NAV_MAX = 200;

    /** The reserved handle for the shared-fragment collection (a convention, not
     *  a kind — 2026-08-15 ledger). Its entries are embedded by slug on every
     *  page, never served as standalone public pages (SVM-4). */
    private const BLOCKS_HANDLE = 'blocks';

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
    private PreviewTokens $previewTokens;
    private PageSectionRegistry $sections;
    private MediaRepository $media;

    /** Absolute path to the active theme directory (for serving its assets). */
    private string $themeDir;

    /** Handle of the collection rendered at `/`, or null for the placeholder. */
    private ?string $home;

    private ?Settings $settings;

    private HeadContributorRegistry $headContributors;

    /** @var array<string,array<string,mixed>>|null memoized live blocks by slug */
    private ?array $blocks = null;

    /** @var array<int,list<array<string,mixed>>> memoized nav list per collection id */
    private array $navByCollection = [];

    /** @var list<string>|bool|null memoized theme.json `nav` opt-in — handles, or true=all */
    private array|bool|null $navOptIn = null;

    public function __construct(
        Connection $db,
        FieldTypeRegistry $types,
        ?string $home = null,
        ?string $themePath = null,
        ?HeadContributorRegistry $headContributors = null,
        ?Settings $settings = null,
        ?PageSectionRegistry $sections = null,
    ) {
        $this->collections      = new CollectionRepository($db);
        $this->entries          = new EntryRepository($db);
        $this->media            = new MediaRepository($db);
        $this->view             = new EntryView($types, new RelationRepository($db), $this->media);
        $this->previewTokens    = new PreviewTokens($db);
        $this->themeDir         = $themePath ?? self::resolveThemeDir($settings);
        $this->render           = new View($this->themeDir, [
            'appName'  => Config::appName(),
            // DB-backed menus (admin Menus editor) override the config/menus.php
            // defaults per name; the file remains the seed/fallback.
            'menus'    => (new Menus($db))->all(),
            'cspNonce' => \Nimbus\Http\Csp::nonce(),
        ]);
        $this->home             = $home;
        $this->settings         = $settings;
        $this->headContributors = $headContributors ?? new HeadContributorRegistry();
        $this->sections         = $sections ?? new PageSectionRegistry();
    }

    /**
     * The active public theme's directory, from the chosen setting — the one place
     * the DB-stored theme choice enters (ADR: the picker). `Config` stays DB-free;
     * the setting overrides the `config/theme.php` default here.
     *
     * The setting is allow-list-validated on write against installed themes, but a
     * theme can be deleted after it was chosen, and any value that becomes a
     * filesystem path earns its own containment check: the resolved path must be a
     * real directory inside `themes/`, or we fall back to the config-file theme
     * (ultimately the bundled starter). So a stale or `../…` value can never point
     * rendering outside the themes directory.
     */
    private static function resolveThemeDir(?Settings $settings): string
    {
        $name = $settings?->theme() ?? Config::theme();
        return (new ThemeCatalog())->dirFor($name) ?? Config::themePath();
    }

    public function routes(Router $r): void
    {
        // Registered first among the site routes: literal, specific paths that
        // must resolve before the {collection} catch-alls ever see them.
        $r->get('/theme/assets/{path*}', fn (Request $req, array $p): Response => $this->asset($p['path']))->name('site.asset');
        $r->get('/sitemap.xml', fn (Request $req, array $p): Response => $this->sitemap())->name('site.sitemap');
        $r->get('/robots.txt', fn (Request $req, array $p): Response => $this->robots())->name('site.robots');
        $r->get('/llms.txt', fn (Request $req, array $p): Response => $this->llmsTxt())->name('site.llms');
        $r->get('/', fn (Request $req, array $p): Response => $this->homePage($req))->name('site.home');

        // Plugin page sections (ADR 0023): a themed public page at a pretty handle,
        // registered BEFORE the {collection} catch-alls so a section resolves to
        // its plugin, never to a same-named collection. The registry is frozen at
        // plugin-load and the handle already passed the reserved-name refusal.
        foreach ($this->sections->handles() as $handle) {
            $r->get('/' . $handle, fn (Request $req, array $p): Response => $this->section($req, $handle))->name('site.section.' . $handle);
            $r->get('/' . $handle . '/{path*}', fn (Request $req, array $p): Response => $this->section($req, $handle))->name('site.section.' . $handle . '.sub');
        }

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
        // A NUL byte makes realpath() throw ValueError (→ an uncaught 500 + logged
        // stack trace); it can never name a real asset, so reject it as a 404
        // before realpath ever sees it (SVM-2).
        if (str_contains($path, "\0")) {
            return $this->assetNotFound();
        }

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
            if (!$this->isPubliclyBrowsable($collection)) {
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
     * robots.txt: crawlers are welcome on the public site, but not the admin or
     * the token-gated API, and the sitemap is advertised so they can find every
     * page. Assets stay crawlable — they help rendering.
     */
    private function robots(): Response
    {
        $lines = [
            'User-agent: *',
            'Disallow: /admin',
            'Disallow: /api',
            '',
            'Sitemap: ' . Config::appUrl() . '/sitemap.xml',
        ];

        return Response::file(implode("\n", $lines) . "\n", 'text/plain; charset=UTF-8');
    }

    /**
     * `/llms.txt` (llmstxt.org) — a plain-text guide for AI agents and crawlers,
     * the agent-facing sibling of robots.txt/sitemap.xml. Beyond listing the
     * public pages, it states the one thing an agent can't infer from the HTML:
     * this is a NimbusCMS site with a token-gated MCP control surface, and how to
     * reach the built-in operating guide.
     *
     * The operational prose is **core-authored**; the only editor-controlled
     * values (site name, description, collection names) sit in structural slots
     * (the H1, the summary blockquote, link text) and are **flattened to a single
     * line** so a value with newlines can't forge a section — an agent reads this
     * as trusted site metadata, so it must not become an instruction channel.
     * Emits **no version string** (the MCP protocol version is negotiated at
     * `initialize`, and a CMS version would be fingerprinting). Lists only
     * publicly browsable collections (never a singleton or the blocks store —
     * SVM-4), matching what robots/sitemap expose.
     */
    private function llmsTxt(): Response
    {
        $base = Config::appUrl();
        $flat = static fn (string $s): string => trim((string) preg_replace('/\s+/', ' ', $s));
        $name = $flat($this->title());
        $desc = $flat($this->settings !== null ? $this->settings->description() : Config::siteDescription());

        $lines = ['# ' . $name, ''];
        if ($desc !== '') {
            $lines[] = '> ' . $desc;
            $lines[] = '';
        }
        $lines[] = 'This is a NimbusCMS site. Beyond the pages below, it is operable by AI agents over '
            . 'the Model Context Protocol (MCP) at ' . $base . '/api/v1/mcp — a token-gated, rate-limited '
            . 'control surface. An agent connects with a scoped API token and reads the built-in operating '
            . 'guide (the `nimbus://guide/core` MCP resource) to learn how to define content types, write '
            . 'entries, and manage the site.';
        $lines[] = '';

        $pages = [];
        foreach ($this->collections->all() as $collection) {
            if ($this->isPubliclyBrowsable($collection)) {
                $pages[] = '- [' . $flat($collection->name) . '](' . $base . '/' . $collection->handle . ')';
            }
        }
        if ($pages !== []) {
            $lines[] = '## Pages';
            $lines[] = '';
            $lines = array_merge($lines, $pages);
            $lines[] = '';
        }
        $lines[] = '## More';
        $lines[] = '';
        $lines[] = '- [Sitemap](' . $base . '/sitemap.xml)';

        return Response::file(implode("\n", $lines) . "\n", 'text/plain; charset=UTF-8');
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
        // The home handle comes from the settings store (DB override) when wired,
        // else the config default. A handle that no longer names a collection —
        // a since-deleted home — resolves to null and shows the placeholder, so
        // a dangling setting never 500s.
        $home       = $this->settings !== null ? $this->settings->home() : $this->home;
        $collection = $home === null ? null : $this->collections->findByHandle($home);
        if ($collection === null) {
            return $this->placeholder();
        }

        if ($collection->isSingle()) {
            $row = $this->entries->findLiveBySlug($collection->id, EntryService::SINGLETON_SLUG);
            return $row === null ? $this->placeholder() : $this->renderEntry($request, $collection, $row);
        }

        return $this->renderCollection($request, $collection, 1);
    }

    /**
     * Is this collection served as its own public pages (an index + entry
     * pages), or not? The `blocks` fragment store (embedded by slug on other
     * pages) and any `single`-kind collection (its one entry is the site home,
     * served at `/`) are **not** browsable: serving them at `/{handle}` would
     * orphan fragments as standalone pages and duplicate the home's canonical
     * (SVM-4). This is the one source of that rule — {@see index()}, {@see show()}
     * and {@see sitemap()} all consult it, so the served surface and the crawled
     * surface can never disagree.
     */
    private function isPubliclyBrowsable(Collection $collection): bool
    {
        return $collection->handle !== self::BLOCKS_HANDLE && !$collection->isSingle();
    }

    /**
     * The collections a theme opts into navigation for, from its `theme.json`
     * `nav` key: a list of collection handles, or `true` for all browsable
     * collections. Read once, hardened — an absent or malformed manifest means
     * no nav (never a 500), mirroring the theme-name fallback in {@see Config}.
     * This is the manifest's first *runtime* read: keep its parse boring.
     *
     * @return list<string>|bool
     */
    private function navOptIn(): array|bool
    {
        if ($this->navOptIn !== null) {
            return $this->navOptIn;
        }
        $this->navOptIn = false;
        $file = $this->themeDir . '/theme.json';
        if (is_file($file)) {
            $decoded = json_decode((string) file_get_contents($file), true);
            $nav     = is_array($decoded) ? ($decoded['nav'] ?? null) : null;
            if ($nav === true) {
                $this->navOptIn = true;
            } elseif (is_array($nav)) {
                $this->navOptIn = array_values(array_filter($nav, 'is_string'));
            }
        }
        return $this->navOptIn;
    }

    /**
     * A collection's live entries for theme navigation — a bounded, **live-only**
     * list with the exact shape of `$entries`, or `[]` when the theme has not
     * opted the collection in (theme.json `nav`) or the collection is not
     * publicly browsable (a singleton or the blocks store — SVM-4, so nav never
     * mints links to pages that 404). It is fed only by the live query, carries
     * only the public `toApi` field values (via {@see EntryView}), and is capped
     * at NAV_MAX in the DB. Memoized so the index and entry code paths share one
     * fetch per request.
     *
     * @return list<array<string,mixed>>
     */
    private function nav(Collection $collection): array
    {
        if (array_key_exists($collection->id, $this->navByCollection)) {
            return $this->navByCollection[$collection->id];
        }
        $optIn  = $this->navOptIn();
        $wanted = $optIn === true || (is_array($optIn) && in_array($collection->handle, $optIn, true));
        $nav    = $wanted && $this->isPubliclyBrowsable($collection)
            ? $this->view->many($collection, $this->entries->liveForCollection($collection->id, self::NAV_MAX, 0))
            : [];

        return $this->navByCollection[$collection->id] = $nav;
    }

    /** A collection's live entries, newest first. */
    private function index(Request $request, string $handle): Response
    {
        $collection = $this->collections->findByHandle($handle);
        if ($collection === null || !$this->isPubliclyBrowsable($collection)) {
            return $this->notFound();
        }

        return $this->renderCollection($request, $collection, max(1, (int) ($request->query('page') ?? 1)));
    }

    /** A single live entry by slug. */
    private function show(Request $request, string $handle, string $slug): Response
    {
        $collection = $this->collections->findByHandle($handle);
        if ($collection === null || !$this->isPubliclyBrowsable($collection)) {
            return $this->notFound();
        }

        // Draft preview (ADR 0021): a valid, entry-scoped token for THIS entry's
        // URL renders the non-live entry. Any invalid/mismatched token falls
        // through to the normal live path — so it leaks nothing (a bad token on a
        // draft URL 404s exactly as before) and a stray ?preview on a live URL
        // still shows the published page.
        $preview = (string) ($request->query('preview') ?? '');
        if ($preview !== '') {
            $granted = $this->previewTokens->resolve($preview);
            if ($granted !== null && $granted['collection_id'] === $collection->id) {
                $draft = $this->entries->findBySlug($collection->id, $slug);
                if ($draft !== null && (int) $draft['id'] === $granted['entry_id']) {
                    return $this->renderEntry($request, $collection, $draft, true);
                }
            }
        }

        $row = $this->entries->findLiveBySlug($collection->id, $slug);
        if ($row === null) {
            // A draft, a scheduled-but-not-due, or a genuinely absent entry all
            // look the same from outside — nothing to distinguish leaks.
            return $this->notFound();
        }

        return $this->renderEntry($request, $collection, $row);
    }

    /**
     * Render a plugin page section (ADR 0023). The plugin's resolver turns the
     * request into a {@see PageView} (template + data + meta), or null → the themed
     * 404 (so a section owns its own not-found without leaking which it was). The
     * result renders through the active theme exactly like a content page — with
     * the section's default templates as a fallback the theme can override, and the
     * CSP nonce handed to the template so a nonce'd `<style>` is possible under the
     * nonce-only public CSP. Sections are GET-only and carry no ambient authority.
     */
    private function section(Request $request, string $handle): Response
    {
        $section = $this->sections->find($handle);
        if ($section === null) {
            return $this->notFound();
        }

        $view = ($section['resolver'])($request);
        if ($view === null) {
            return $this->notFound();
        }

        $title       = isset($view->meta['title']) && $view->meta['title'] !== '' ? $view->meta['title'] : ucfirst($handle);
        $description = $view->meta['description'] ?? '';
        $ogType      = $view->meta['og_type'] ?? 'website';

        $data = array_merge($view->data, [
            'title'   => $title,
            'meta'    => $this->meta($request->path, $title, $this->clip($description), $ogType),
            'head'    => $this->headContributors->render(new PageContext('section', Config::appUrl() . $request->path, $title, $this->title(), Csp::nonce(), null, null)),
            // The CSP nonce for a section template's own nonce'd <style>/<script>
            // (the public CSP is nonce-only — no inline style=).
            'cspNonce' => Csp::nonce(),
            'section'  => $handle,
            // Resolve a core media id to a public {url, alt} (or null) — the general
            // way a section renders an image it holds only by id (ADR 0022/0023),
            // fail-safe when the media was deleted.
            'media'    => fn (?int $id): ?array => $this->mediaInfo($id),
        ]);

        // Render through the theme, falling back to the section's own templates
        // (ADR 0023). The layout stays the theme's.
        $render   = $this->render->withFallback($section['templates']);
        $response = $this->renderPage($view->template, $data, $view->status, false, $render);

        // A per-user section (a cart, an account page) must never be cached by a
        // shared CDN or the browser — one visitor's page served to another is a
        // leak. Section paths already bail the server page-cache; this adds the
        // response headers (ADR 0023 private flag / ADR 0026).
        if ($view->private) {
            $response = $response
                ->withHeader('Cache-Control', 'no-store, private')
                ->withHeader('Referrer-Policy', 'no-referrer')
                ->withHeader('X-Robots-Tag', 'noindex');
        }
        return $response;
    }

    /**
     * Resolve a core media id to a public `{url, alt}` for a section template, or
     * null when the id is null or the media was deleted (fail-safe — a dangling
     * image id never 500s, ADR 0022). The URL/alt are the stored values; the
     * template still escapes them on output.
     *
     * @return array{url:string,alt:?string}|null
     */
    private function mediaInfo(?int $id): ?array
    {
        if ($id === null) {
            return null;
        }
        $item = $this->media->find($id);
        return $item === null ? null : ['url' => $item->url, 'alt' => $item->alt];
    }

    /** Render a collection's live entry index (paginated). */
    private function renderCollection(Request $request, Collection $collection, int $page): Response
    {
        $total      = $this->entries->countLive($collection->id);
        $totalPages = $total === 0 ? 0 : (int) ceil($total / self::PER_PAGE);
        // A page past the last one does not exist → 404, not a 200 empty list.
        // A 200 would be stored per distinct ?page=N (Application::cacheKey), the
        // page-cache disk-fill vector (SVM-1); a 404 is never cached. Page 1 is
        // always valid — an empty collection's page 1 is a legitimate "no entries"
        // view (keyed with no ?page suffix, one file).
        if ($page > 1 && $page > $totalPages) {
            return $this->notFound();
        }
        $rows  = $this->entries->liveForCollection($collection->id, self::PER_PAGE, ($page - 1) * self::PER_PAGE);

        $info = ['handle' => $collection->handle, 'name' => $collection->name];
        $kind = $request->path === '/' ? 'home' : 'collection';

        return $this->renderPage($this->specialize('collection', $collection->handle), [
            'title'       => $collection->name,
            'meta'        => $this->meta($request->path, $collection->name, $this->describe(null, $collection), 'website'),
            'head'        => $this->headContributors->render(new PageContext($kind, Config::appUrl() . $request->path, $collection->name, $this->title(), Csp::nonce(), null, $info)),
            'collection'  => $info,
            'entries'     => $this->view->many($collection, $rows),
            'nav'         => $this->nav($collection),
            'page'        => $page,
            'total_pages' => $totalPages,
        ]);
    }

    /**
     * Render one live entry.
     *
     * @param array<string,mixed> $row a live nb_entries row
     */
    private function renderEntry(Request $request, Collection $collection, array $row, bool $preview = false): Response
    {
        $entry = $this->view->one($collection, $row);
        $info  = ['handle' => $collection->handle, 'name' => $collection->name];
        $kind  = $request->path === '/' ? 'home' : 'entry';

        return $this->renderPage($this->specialize('entry', $collection->handle), [
            'title'      => (string) $row['title'],
            'meta'       => $this->meta($request->path, (string) $row['title'], $this->describe($entry, $collection), 'article'),
            'head'       => $this->headContributors->render(new PageContext($kind, Config::appUrl() . $request->path, (string) $row['title'], $this->title(), Csp::nonce(), $entry, $info)),
            'collection' => $info,
            'entry'      => $entry,
            'nav'        => $this->nav($collection),
        ], 200, $preview);
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
        return $this->settings !== null ? $this->settings->description() : Config::siteDescription();
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
     * @param ?View $render the View to render with — defaults to the theme View;
     *                      a page section passes one with its templates as fallback
     */
    private function renderPage(string $template, array $data, int $status = 200, bool $preview = false, ?View $render = null): Response
    {
        $render ??= $this->render;
        $data['blocks'] = $this->blocks();
        // Resolve the site title from the store (DB override ?? file default) and
        // set it as a shared global — so the layout AND every nested partial
        // (header/footer brand) reflect the editable setting consistently. Done
        // at render time, so /api and cache-hit requests never run the query.
        $render->share('appName', $this->title());
        // In demo mode, core adds the "live demo" banner — so any theme works in
        // the hosted sandbox without carrying demo markup (a no-op otherwise).
        $html = DemoBanner::inject($render->render($template, $data));
        if (!$preview) {
            return Response::html($html, $status);
        }
        // A draft preview (ADR 0021): core adds the "unpublished draft" banner and
        // hardens the response — never store it (a preview must not enter the page
        // cache or a shared CDN), never send the ?preview token in the Referer, and
        // keep the draft URL out of search indexes.
        return Response::html(PreviewBanner::inject($html), $status)
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('Referrer-Policy', 'no-referrer')
            ->withHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    /** The site title: the stored setting, or the config default when unwired. */
    private function title(): string
    {
        return $this->settings !== null ? $this->settings->title() : Config::appName();
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
        $collection = $this->collections->findByHandle(self::BLOCKS_HANDLE);
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
        $name = View::e($this->title());
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

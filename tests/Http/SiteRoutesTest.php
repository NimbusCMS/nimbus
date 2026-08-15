<?php

declare(strict_types=1);

namespace Nimbus\Tests\Http;

use Nimbus\Content\Collection;
use Nimbus\Content\EntryInput;
use Nimbus\Content\EntryRepository;
use Nimbus\Content\EntryService;
use Nimbus\Content\FieldTypeRegistry;
use Nimbus\Content\RelationRepository;
use Nimbus\Http\Response;
use Nimbus\Http\Router;
use Nimbus\Site\SiteController;
use Nimbus\Support\EventDispatcher;

/**
 * The server-rendered public site, through the real kernel.
 *
 * The starter theme renders real Requests into HTML. These assert on the page
 * that comes back: only the live set appears, drafts are absent, output is
 * escaped, and the public routes never shadow /admin or /api.
 */
final class SiteRoutesTest extends HttpTestCase
{
    private EntryService $entryService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->entryService = new EntryService(
            $this->db,
            new EntryRepository($this->db),
            new RelationRepository($this->db),
            new FieldTypeRegistry(),
            new EventDispatcher(),
        );
    }

    /** @return array{handle:string,label:string,type:string,required:bool,options:array<string,mixed>} */
    private function field(string $handle, string $type = 'text'): array
    {
        return ['handle' => $handle, 'label' => ucfirst($handle), 'type' => $type, 'required' => false, 'options' => []];
    }

    /** @param array<string,mixed> $values */
    private function publish(Collection $c, string $title, string $slug, string $status = 'published', ?string $at = null, array $values = []): int
    {
        return (int) $this->entryService->save($c, new EntryInput($title, $slug, $status, $values, $at), null, null)->entryId;
    }

    /**
     * Dispatch `GET /` against a SiteController whose home is $home, without
     * touching the on-disk config/site.php. Mirrors how the kernel wires it.
     */
    private function homeWith(?string $home): Response
    {
        $router = new Router();
        (new SiteController($this->db, new FieldTypeRegistry(), $home))->routes($router);
        $response = $router->dispatch($this->request('GET', '/'));
        self::assertNotNull($response, 'GET / must resolve');
        /** @var Response $response */
        return $response;
    }

    /** @param array<int,array<string,mixed>> $fields */
    private function singleCollection(string $handle, array $fields = []): Collection
    {
        return $this->makeCollection($handle, $fields, ['kind' => 'single', 'permissions' => ['manage' => ['editor']]]);
    }

    // ---------------------------------------------------------- collection page

    public function test_a_collection_page_lists_only_its_live_entries(): void
    {
        $c = $this->makeCollection('posts');
        $this->publish($c, 'Live Post', 'live');
        $this->publish($c, 'A Draft', 'draft-one', 'draft');
        $this->publish($c, 'Scheduled', 'later', 'published', (new \DateTimeImmutable('+2 days'))->format('Y-m-d H:i:s'));

        $response = $this->get('/posts');

        self::assertSame(200, $response->status);
        self::assertStringContainsString('text/html', (string) $response->header('Content-Type'));
        self::assertStringContainsString('Live Post', $response->body);
        self::assertStringContainsString('href="/posts/live"', $response->body);
        self::assertStringNotContainsString('A Draft', $response->body, 'a draft never appears on the public site');
        self::assertStringNotContainsString('Scheduled', $response->body, 'a not-yet-due entry never appears');
    }

    public function test_an_empty_collection_page_still_renders(): void
    {
        $this->makeCollection('posts');

        $response = $this->get('/posts');

        self::assertSame(200, $response->status);
        self::assertStringContainsString('Nothing published here yet', $response->body);
    }

    // --------------------------------------------------------------- entry page

    public function test_an_entry_page_renders_its_fields(): void
    {
        $c = $this->makeCollection('posts', [$this->field('body', 'textarea')]);
        $this->publish($c, 'Hello World', 'hello', 'published', null, ['body' => 'The body text.']);

        $response = $this->get('/posts/hello');

        self::assertSame(200, $response->status);
        self::assertStringContainsString('Hello World', $response->body);
        self::assertStringContainsString('The body text.', $response->body);
        self::assertStringContainsString('href="/posts"', $response->body, 'the entry links back to its collection');
    }

    public function test_a_draft_entry_is_not_found_by_slug(): void
    {
        $c = $this->makeCollection('posts');
        $this->publish($c, 'Secret', 'secret', 'draft');

        $response = $this->get('/posts/secret');

        self::assertSame(404, $response->status, 'a draft must be indistinguishable from absent');
    }

    public function test_a_scheduled_entry_is_not_found_by_slug(): void
    {
        $c = $this->makeCollection('posts');
        $this->publish($c, 'Soon', 'soon', 'published', (new \DateTimeImmutable('+1 day'))->format('Y-m-d H:i:s'));

        self::assertSame(404, $this->get('/posts/soon')->status);
    }

    public function test_an_unknown_collection_is_not_found(): void
    {
        self::assertSame(404, $this->get('/nope')->status);
    }

    // ------------------------------------------------------------------ escaping

    public function test_entry_output_is_escaped(): void
    {
        $c = $this->makeCollection('posts', [$this->field('body', 'textarea')]);
        $this->publish($c, 'Safe <script>alert(1)</script>', 'xss', 'published', null, ['body' => '<b>raw</b>']);

        $response = $this->get('/posts/xss');

        self::assertSame(200, $response->status);
        self::assertStringNotContainsString('<script>alert(1)</script>', $response->body, 'a title is escaped');
        self::assertStringNotContainsString('<b>raw</b>', $response->body, 'field values are escaped');
        self::assertStringContainsString('&lt;script&gt;', $response->body);
    }

    // -------------------------------------------------- does not shadow the app

    public function test_the_public_site_does_not_shadow_admin(): void
    {
        // /admin/login is a two-segment path the {collection}/{slug} route could
        // in principle catch — but /admin routes register first, so it doesn't.
        $response = $this->get('/admin/login');

        self::assertSame(200, $response->status);
        self::assertStringNotContainsString('Nothing lives here', $response->body);
    }

    public function test_the_public_site_does_not_shadow_the_api(): void
    {
        $this->makeCollection('posts');

        // No bearer token: the API owns this path and rejects it (401). If the
        // site controller had intercepted, this would be a 404 HTML page.
        $response = $this->get('/api/v1/collections/posts/entries');

        self::assertSame(401, $response->status);
        self::assertStringContainsString('application/json', (string) $response->header('Content-Type'));
    }

    // ------------------------------------------------------------- home page

    public function test_a_single_collection_can_be_the_home_page(): void
    {
        $home = $this->singleCollection('homepage', [$this->field('body', 'textarea')]);
        // A single collection stores its one entry at the __singleton slug.
        $this->publish($home, 'Welcome', 'ignored', 'published', null, ['body' => 'Front page body.']);

        $response = $this->homeWith('homepage');

        self::assertSame(200, $response->status);
        self::assertStringContainsString('Front page body.', $response->body);
    }

    public function test_a_collection_can_be_the_home_page_as_its_index(): void
    {
        $posts = $this->makeCollection('posts');
        $this->publish($posts, 'First Post', 'first');
        $this->publish($posts, 'Second Post', 'second');

        $response = $this->homeWith('posts');

        self::assertSame(200, $response->status);
        self::assertStringContainsString('First Post', $response->body);
        self::assertStringContainsString('href="/posts/first"', $response->body);
    }

    public function test_an_unconfigured_home_is_a_placeholder(): void
    {
        $response = $this->homeWith(null);

        self::assertSame(200, $response->status);
        self::assertStringContainsString('config/site.php', $response->body);
    }

    public function test_a_home_naming_a_missing_collection_is_a_placeholder(): void
    {
        $response = $this->homeWith('does-not-exist');

        self::assertSame(200, $response->status, 'a misconfigured home must not 500');
        self::assertStringContainsString('config/site.php', $response->body);
    }

    public function test_a_draft_single_home_does_not_leak(): void
    {
        $home = $this->singleCollection('homepage', [$this->field('body', 'textarea')]);
        $this->publish($home, 'Secret Front Page', 'ignored', 'draft', null, ['body' => 'Unpublished.']);

        $response = $this->homeWith('homepage');

        self::assertSame(200, $response->status);
        self::assertStringNotContainsString('Secret Front Page', $response->body, 'a draft home never renders');
        self::assertStringNotContainsString('Unpublished.', $response->body);
        self::assertStringContainsString('config/site.php', $response->body, 'it falls through to the placeholder');
    }

    public function test_the_kernel_serves_the_placeholder_at_root_by_default(): void
    {
        // The committed config/site.php configures no home, so the real kernel
        // serves the placeholder — and it points people at the admin.
        $response = $this->get('/');

        self::assertSame(200, $response->status);
        self::assertStringContainsString('/admin', $response->body);
    }

    // ------------------------------------------------------ theme capabilities

    public function test_the_layout_composes_header_and_footer_partials(): void
    {
        $c = $this->makeCollection('posts');
        $this->publish($c, 'Hello', 'hello');

        $response = $this->get('/posts/hello');

        self::assertSame(200, $response->status);
        self::assertStringContainsString('<header>', $response->body, 'the header partial rendered');
        self::assertStringContainsString('Powered by', $response->body, 'the footer partial rendered');
    }

    public function test_a_missing_page_uses_the_themed_404(): void
    {
        $response = $this->get('/nope');

        self::assertSame(404, $response->status);
        self::assertStringContainsString('Nothing lives at this address', $response->body, 'the themed 404 body');
        self::assertStringContainsString('Powered by', $response->body, 'rendered inside the theme layout');
    }

    public function test_a_theme_can_specialize_a_template_per_collection(): void
    {
        $themePath = dirname(__DIR__) . '/fixtures/themes/spec';
        $posts = $this->makeCollection('posts');
        $this->publish($posts, 'Hello', 'hello');
        $news = $this->makeCollection('news');
        $this->publish($news, 'Yo', 'yo');

        $router = new Router();
        (new SiteController($this->db, new FieldTypeRegistry(), null, $themePath))->routes($router);

        $posted = $router->dispatch($this->request('GET', '/posts/hello'));
        self::assertNotNull($posted);
        /** @var Response $posted */
        self::assertStringContainsString('POSTS ENTRY: Hello', $posted->body, 'entry-posts overrides entry');

        $generic = $router->dispatch($this->request('GET', '/news/yo'));
        self::assertNotNull($generic);
        /** @var Response $generic */
        self::assertStringContainsString('GENERIC ENTRY: Yo', $generic->body, 'falls back to entry with no override');
    }

    // --------------------------------------------------------- theme assets

    public function test_a_theme_asset_is_served_with_its_content_type(): void
    {
        $response = $this->get('/theme/assets/app.css');

        self::assertSame(200, $response->status);
        self::assertStringContainsString('text/css', (string) $response->header('Content-Type'));
        self::assertStringContainsString('font-family', $response->body, 'the real stylesheet is served');
        self::assertNotNull($response->header('Cache-Control'), 'assets are cacheable');
    }

    public function test_a_missing_asset_is_404(): void
    {
        self::assertSame(404, $this->get('/theme/assets/nope.css')->status);
    }

    public function test_asset_path_traversal_cannot_escape_the_assets_directory(): void
    {
        // The theme's own templates live one directory up from assets/ — a `..`
        // must not reach them (or anything else outside assets/).
        $response = $this->get('/theme/assets/../templates/layout.php');

        self::assertSame(404, $response->status, 'traversal never resolves to a real file');
        self::assertStringNotContainsString('<!doctype', $response->body, 'a template is never disclosed');
    }
}

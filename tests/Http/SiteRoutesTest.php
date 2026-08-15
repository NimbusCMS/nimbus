<?php

declare(strict_types=1);

namespace Nimbus\Tests\Http;

use Nimbus\Content\Collection;
use Nimbus\Content\EntryInput;
use Nimbus\Content\EntryRepository;
use Nimbus\Content\EntryService;
use Nimbus\Content\FieldTypeRegistry;
use Nimbus\Content\RelationRepository;
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
}

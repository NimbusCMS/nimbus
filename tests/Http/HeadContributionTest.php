<?php

declare(strict_types=1);

namespace Nimbus\Tests\Http;

use Nimbus\Content\Collection;
use Nimbus\Content\FieldTypeRegistry;
use Nimbus\Http\Response;
use Nimbus\Http\Router;
use Nimbus\Site\HeadContributor;
use Nimbus\Site\HeadContributorRegistry;
use Nimbus\Site\PageContext;
use Nimbus\Site\SiteController;

/**
 * The head-contribution capability through the real site renderer: what a
 * plugin registers reaches the rendered <head>, and it is handed the page's
 * context (ADR 0004).
 */
final class HeadContributionTest extends HttpTestCase
{
    private function seedLive(Collection $c, string $title, string $slug): void
    {
        $this->db->insert(
            "INSERT INTO nb_entries (collection_id, title, slug, status, data, published_at, created_at, updated_at)
             VALUES (:c, :t, :s, 'published', '{}', NOW(), NOW(), NOW())",
            ['c' => $c->id, 't' => $title, 's' => $slug],
        );
    }

    public function test_a_contributor_reaches_the_head_and_receives_the_page_context(): void
    {
        $contributor = new class () implements HeadContributor {
            public ?PageContext $seen = null;

            public function head(PageContext $page): string
            {
                $this->seen = $page;
                return '<meta name="marker" content="' . $page->kind . '">';
            }
        };

        $registry = new HeadContributorRegistry();
        $registry->add($contributor, 'nimbuscms.seo');

        $c = $this->makeCollection('posts');
        $this->seedLive($c, 'Hello', 'hello');

        $router = new Router();
        (new SiteController($this->db, new FieldTypeRegistry(), null, null, $registry))->routes($router);
        $response = $router->dispatch($this->request('GET', '/posts/hello'));

        self::assertNotNull($response);
        /** @var Response $response */
        self::assertStringContainsString('<meta name="marker" content="entry">', $response->body, 'the contribution reaches <head>');

        $seen = $contributor->seen;
        self::assertNotNull($seen);
        /** @var PageContext $seen */
        self::assertSame('entry', $seen->kind);
        self::assertStringContainsString('/posts/hello', $seen->canonical);

        $entry = $seen->entry;
        self::assertIsArray($entry);
        /** @var array<string,mixed> $entry */
        self::assertSame('Hello', $entry['title'] ?? null, 'the entry view-model is in the context');
    }

    public function test_no_contributors_leaves_the_head_unchanged(): void
    {
        $c = $this->makeCollection('posts');
        $this->seedLive($c, 'Hello', 'hello');

        // The real kernel with no plugins registered: the page still renders.
        $response = $this->get('/posts/hello');

        self::assertSame(200, $response->status);
        self::assertStringNotContainsString('name="marker"', $response->body);
    }
}

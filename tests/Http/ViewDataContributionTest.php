<?php

declare(strict_types=1);

namespace Nimbus\Tests\Http;

use Nimbus\Content\Collection;
use Nimbus\Content\FieldTypeRegistry;
use Nimbus\Http\Response;
use Nimbus\Http\Router;
use Nimbus\Site\PageContext;
use Nimbus\Site\SiteController;
use Nimbus\Site\ViewDataContributor;
use Nimbus\Site\ViewDataContributorRegistry;

/**
 * The view-data-contribution capability through the real site renderer (ADR 0027):
 * what a plugin contributes reaches the theme's `contrib` view-model under its own
 * namespace, and the contributor is handed the page context — never a Request.
 */
final class ViewDataContributionTest extends HttpTestCase
{
    private function seedLive(Collection $c, string $title, string $slug): void
    {
        $this->db->insert(
            "INSERT INTO nb_entries (collection_id, title, slug, status, data, published_at, created_at, updated_at)
             VALUES (:c, :t, :s, 'published', '{}', NOW(), NOW(), NOW())",
            ['c' => $c->id, 't' => $title, 's' => $slug],
        );
    }

    public function test_contributed_data_reaches_the_theme_and_gets_the_page_context(): void
    {
        $contributor = new class () implements ViewDataContributor {
            public ?PageContext $seen = null;

            public function data(PageContext $page): array
            {
                $this->seen = $page;
                return ['marker' => 'HELLO-CONTRIB'];
            }
        };

        $registry = new ViewDataContributorRegistry();
        $registry->add($contributor, 'test.viewdata');

        $themePath = dirname(__DIR__) . '/fixtures/themes/spec';
        $news = $this->makeCollection('news'); // no entry-news template → generic entry.php
        $this->seedLive($news, 'Yo', 'yo');

        $router = new Router();
        (new SiteController($this->db, new FieldTypeRegistry(), null, $themePath, null, null, null, $registry))->routes($router);
        $response = $router->dispatch($this->request('GET', '/news/yo'));

        self::assertNotNull($response);
        /** @var Response $response */
        self::assertStringContainsString('CONTRIB:HELLO-CONTRIB', $response->body, 'the contribution reaches the theme under contrib[provider]');

        $seen = $contributor->seen;
        self::assertNotNull($seen);
        /** @var PageContext $seen */
        self::assertSame('entry', $seen->kind, 'the contributor is handed the page context');
    }

    public function test_no_contributors_renders_the_page_unchanged(): void
    {
        $themePath = dirname(__DIR__) . '/fixtures/themes/spec';
        $news = $this->makeCollection('news');
        $this->seedLive($news, 'Yo', 'yo');

        $router = new Router();
        (new SiteController($this->db, new FieldTypeRegistry(), null, $themePath))->routes($router);
        $response = $router->dispatch($this->request('GET', '/news/yo'));

        self::assertNotNull($response);
        /** @var Response $response */
        self::assertStringContainsString('GENERIC ENTRY: Yo', $response->body);
        self::assertStringNotContainsString('CONTRIB:', $response->body, 'no contributors → no contrib output');
    }
}

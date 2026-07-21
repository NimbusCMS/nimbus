<?php

declare(strict_types=1);

namespace Nimbus\Tests\Http;

use Nimbus\Admin\CollectionsController;
use Nimbus\Admin\EntriesController;
use Nimbus\Content\EntryRepository;
use Nimbus\Content\EntryService;
use Nimbus\Content\RelationRepository;
use Nimbus\Http\Router;
use ReflectionClass;

/**
 * Guards the split between schema administration and content editing.
 *
 * The two controllers answer to different people under different rules —
 * defining a content type changes the shape existing entries are read against,
 * while writing an entry is everyday editorial work. Without a test, the
 * boundary erodes the first time someone adds "just one" entry route to the
 * collections controller.
 */
final class ControllerBoundaryTest extends HttpTestCase
{
    private function routesOf(object $controller): Router
    {
        $router = new Router();
        $controller->routes($router);
        return $router;
    }

    public function test_collections_controller_owns_no_entry_routes(): void
    {
        foreach ($this->routesOf(new CollectionsController($this->db, $this->auth))->routes() as $route) {
            self::assertStringNotContainsString(
                '/entries',
                $route->pattern,
                "{$route->method} {$route->pattern} belongs to EntriesController",
            );
        }
    }

    public function test_entries_controller_owns_only_entry_routes(): void
    {
        $routes = $this->routesOf(new EntriesController($this->db, $this->auth))->routes();

        self::assertNotEmpty($routes);
        foreach ($routes as $route) {
            self::assertStringContainsString(
                '/entries',
                $route->pattern,
                "{$route->method} {$route->pattern} is not an entry route",
            );
        }
    }

    public function test_the_split_lost_no_routes(): void
    {
        $served = [];
        foreach ($this->router->routes() as $route) {
            $served[] = $route->method . ' ' . $route->pattern;
        }

        // Every URL the admin relied on before the split still resolves.
        foreach ([
            'GET /admin/collections',
            'GET /admin/collections/new',
            'POST /admin/collections',
            'GET /admin/collections/{id}/edit',
            'POST /admin/collections/{id}',
            'POST /admin/collections/{id}/delete',
            'GET /admin/collections/{handle}/entries',
            'GET /admin/collections/{handle}/entries/new',
            'POST /admin/collections/{handle}/entries',
            'GET /admin/collections/{handle}/entries/{id}/edit',
            'POST /admin/collections/{handle}/entries/{id}',
            'POST /admin/collections/{handle}/entries/{id}/delete',
        ] as $expected) {
            self::assertContains($expected, $served);
        }
    }

    public function test_collection_and_entry_patterns_do_not_shadow_each_other(): void
    {
        // `{id}` matches a single segment, so /admin/collections/posts/entries
        // must never be swallowed by the collection-update route registered
        // before it.
        $this->actingAs('admin');
        $this->makeCollection('posts');

        $this->assertOkHtml($this->get('/admin/collections/posts/entries'));
        $this->assertOkHtml($this->get('/admin/collections/new'));
    }

    public function test_collections_controller_does_not_depend_on_entry_writes(): void
    {
        $forbidden = [EntryService::class, EntryRepository::class, RelationRepository::class];

        foreach ((new ReflectionClass(CollectionsController::class))->getProperties() as $property) {
            $type = (string) $property->getType();
            self::assertNotContains(
                $type,
                $forbidden,
                "CollectionsController still holds {$type}; entry writes belong to EntriesController",
            );
        }
    }

    public function test_neither_controller_extends_a_shared_crud_base(): void
    {
        // Deliberate: no generic resource controller. Both extend the thin admin
        // Controller (view, nav, redirect, CSRF) and nothing more.
        foreach ([CollectionsController::class, EntriesController::class] as $class) {
            $parent = (new ReflectionClass($class))->getParentClass();

            self::assertNotFalse($parent);
            self::assertSame(\Nimbus\Admin\Controller::class, $parent->getName());
            self::assertTrue($parent->isAbstract());
        }
    }
}

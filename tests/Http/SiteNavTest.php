<?php

declare(strict_types=1);

namespace Nimbus\Tests\Http;

use Nimbus\Content\Collection;
use Nimbus\Content\EntryInput;
use Nimbus\Content\EntryRepository;
use Nimbus\Content\EntryService;
use Nimbus\Content\Field;
use Nimbus\Content\FieldTypeRegistry;
use Nimbus\Content\FieldTypes\BaseType;
use Nimbus\Content\RelationRepository;
use Nimbus\Http\Response;
use Nimbus\Http\Router;
use Nimbus\Site\SiteController;
use Nimbus\Support\EventDispatcher;

/**
 * A field type whose public `toApi()` value differs from what is stored — used
 * to prove `$nav` carries the public value, never raw storage.
 */
final class NavXformType extends BaseType
{
    public function type(): string
    {
        return 'xform';
    }

    public function renderInput(Field $field, mixed $value): string
    {
        return '';
    }

    public function toApi(Field $field, mixed $value): mixed
    {
        return $value === null || $value === '' ? null : 'SHOWN';
    }
}

/**
 * The public `$nav` view-model (a docs-sidebar list). Guards the controls the
 * pre-build review required: live-only source (no draft/scheduled leak), public
 * toApi values (never raw storage), the theme.json opt-in, and the
 * isPubliclyBrowsable gate (a singleton/blocks collection mints no nav — SVM-4).
 */
final class SiteNavTest extends HttpTestCase
{
    private FieldTypeRegistry $types;
    private EntryService $entryService;

    private const NAVDEMO = __DIR__ . '/../fixtures/themes/navdemo';
    private const NAVNONE = __DIR__ . '/../fixtures/themes/navnone';

    protected function setUp(): void
    {
        parent::setUp();
        $this->types = new FieldTypeRegistry();
        $this->types->register(new NavXformType());
        $this->entryService = new EntryService($this->db, new EntryRepository($this->db), new RelationRepository($this->db), $this->types, new EventDispatcher());
    }

    /** @return array{handle:string,label:string,type:string,required:bool,options:array<string,mixed>} */
    private function field(string $handle, string $type = 'textarea'): array
    {
        return ['handle' => $handle, 'label' => ucfirst($handle), 'type' => $type, 'required' => false, 'options' => []];
    }

    /** @param array<string,mixed> $values */
    private function publish(Collection $c, string $title, string $slug, string $status = 'published', ?string $at = null, array $values = []): void
    {
        $this->entryService->save($c, new EntryInput($title, $slug, $status, $values, $at), null, null);
    }

    private function render(string $path, string $theme = self::NAVDEMO, ?string $home = null): Response
    {
        $router = new Router();
        (new SiteController($this->db, $this->types, $home, $theme))->routes($router);
        $response = $router->dispatch($this->request('GET', $path));
        self::assertNotNull($response, "GET {$path} must resolve");
        /** @var Response $response */
        return $response;
    }

    public function test_nav_never_contains_a_draft_or_scheduled_entry(): void
    {
        $posts = $this->makeCollection('posts', [$this->field('body')]);
        $this->publish($posts, 'Live', 'live', 'published', null, ['body' => 'B1']);
        $this->publish($posts, 'Draft', 'draft', 'draft', null, ['body' => 'B2']);
        $this->publish($posts, 'Future', 'future', 'published', '2999-01-01 00:00:00', ['body' => 'B3']);

        foreach (['/posts', '/posts/live'] as $path) {
            $body = $this->render($path)->body;
            self::assertStringContainsString('live=B1', $body, "nav lists the live entry on {$path}");
            self::assertStringNotContainsString('draft=', $body, "no draft in nav on {$path}");
            self::assertStringNotContainsString('future=', $body, "no scheduled entry in nav on {$path}");
        }
    }

    public function test_nav_field_values_are_the_public_toapi_values_never_raw(): void
    {
        $posts = $this->makeCollection('posts', [$this->field('body', 'xform')]);
        $this->publish($posts, 'A', 'a', 'published', null, ['body' => 'RAWSECRET']);

        $body = $this->render('/posts')->body;
        self::assertStringContainsString('a=SHOWN', $body, 'nav carries the toApi value');
        self::assertStringNotContainsString('RAWSECRET', $body, 'nav never exposes the raw stored value');
    }

    public function test_nav_is_empty_for_a_non_browsable_single_collection(): void
    {
        // `home` is opted in, but a singleton is not publicly browsable (SVM-4),
        // so nav must not mint links to entries that 404 at /{handle}/{slug}.
        $home = $this->makeCollection('home', [$this->field('body')], ['kind' => 'single', 'permissions' => ['manage' => []]]);
        $this->publish($home, 'Home', EntryService::SINGLETON_SLUG, 'published', null, ['body' => 'HB']);

        $body = $this->render('/', self::NAVDEMO, 'home')->body;
        self::assertStringContainsString('NAV{}', $body, 'a singleton home gets an empty nav');
    }

    public function test_nav_is_empty_for_a_collection_the_theme_did_not_opt_in(): void
    {
        $pages = $this->makeCollection('pages', [$this->field('body')]); // not in the nav opt-in list
        $this->publish($pages, 'P', 'p', 'published', null, ['body' => 'PB']);

        self::assertStringContainsString('NAV{}', $this->render('/pages')->body);
    }

    public function test_nav_is_empty_when_the_theme_has_no_manifest(): void
    {
        $posts = $this->makeCollection('posts', [$this->field('body')]);
        $this->publish($posts, 'A', 'a', 'published', null, ['body' => 'B']);

        // navnone renders nav but ships no theme.json — the opt-in is absent.
        self::assertStringContainsString('NAV{}', $this->render('/posts', self::NAVNONE)->body);
    }

    public function test_nav_contains_only_this_collections_entries(): void
    {
        $posts  = $this->makeCollection('posts', [$this->field('body')]);
        $guides = $this->makeCollection('guides', [$this->field('body')]);
        $this->publish($posts, 'PA', 'pa', 'published', null, ['body' => 'X']);
        $this->publish($guides, 'GA', 'ga', 'published', null, ['body' => 'Y']);

        $body = $this->render('/posts')->body;
        self::assertStringContainsString('pa=X', $body);
        self::assertStringNotContainsString('ga=', $body, 'nav for one collection never lists another');
    }
}

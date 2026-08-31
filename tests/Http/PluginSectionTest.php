<?php

declare(strict_types=1);

namespace Nimbus\Tests\Http;

use Nimbus\Content\FieldTypeRegistry;
use Nimbus\Http\Request;
use Nimbus\Http\Response;
use Nimbus\Http\Router;
use Nimbus\Site\PageSectionRegistry;
use Nimbus\Site\PageView;
use Nimbus\Site\SiteController;

/**
 * A plugin page section (ADR 0023) rendered through the real SiteController and
 * the theme: it resolves to the plugin's view-model, renders inside the theme
 * layout with the section's default templates as a fallback, escapes author
 * values, and a resolver's null becomes the themed 404. GET-only, no ambient
 * authority.
 */
final class PluginSectionTest extends HttpTestCase
{
    private string $templates;

    protected function setUp(): void
    {
        parent::setUp();
        // A stand-in for the plugin's shipped default templates. The `$e` and
        // `$cspNonce` helpers/values are what SiteController hands a section.
        $this->templates = sys_get_temp_dir() . '/nimbus-section-' . bin2hex(random_bytes(4));
        mkdir($this->templates);
        file_put_contents(
            $this->templates . '/shop-index.php',
            '<style nonce="<?= $e($cspNonce) ?>">.shop{}</style>'
            . '<h1 class="shop">Shop</h1><ul><?php foreach ($items as $it): ?>'
            . '<li><?= $e($it) ?></li><?php endforeach; ?></ul>',
        );
        file_put_contents(
            $this->templates . '/shop-product.php',
            '<h1 class="product"><?= $e($sku) ?></h1>',
        );
    }

    protected function tearDown(): void
    {
        @unlink($this->templates . '/shop-index.php');
        @unlink($this->templates . '/shop-product.php');
        @rmdir($this->templates);
        parent::tearDown();
    }

    /** @param callable(Request):?PageView $resolver */
    private function sectionRouter(callable $resolver): Router
    {
        $registry = new PageSectionRegistry();
        $registry->add('shop', $resolver, $this->templates, 'nimbuscms.storefront');

        $router = new Router();
        (new SiteController($this->db, new FieldTypeRegistry(), null, null, null, null, $registry))->routes($router);
        return $router;
    }

    private function dispatch(Router $router, string $path): Response
    {
        $response = $router->dispatch($this->request('GET', $path));
        self::assertNotNull($response, "GET {$path} must resolve");
        /** @var Response $response */
        return $response;
    }

    public function test_a_section_renders_through_the_theme_layout(): void
    {
        $router = $this->sectionRouter(static fn (Request $r): PageView
            => new PageView('shop-index', ['items' => ['Milk', 'Bread']], ['title' => 'Shop']));

        $response = $this->dispatch($router, '/shop');

        self::assertSame(200, $response->status);
        self::assertStringContainsString('<h1 class="shop">Shop</h1>', $response->body, 'section body from the plugin default template');
        self::assertStringContainsString('<li>Milk</li>', $response->body);
        self::assertStringContainsString('Powered by', $response->body, 'wrapped in the theme layout');
    }

    public function test_a_section_escapes_author_values_on_render(): void
    {
        // The ADR-0022 escape-on-render contract, now executed: a hostile item
        // name is escaped by the template's $e, never live markup.
        $router = $this->sectionRouter(static fn (Request $r): PageView
            => new PageView('shop-index', ['items' => ['<script>alert(1)</script>']], ['title' => 'Shop']));

        $response = $this->dispatch($router, '/shop');

        self::assertStringNotContainsString('<script>alert(1)</script>', $response->body);
        self::assertStringContainsString('&lt;script&gt;', $response->body);
    }

    public function test_the_section_template_receives_the_csp_nonce(): void
    {
        // The public CSP is nonce-only; a section's <style> must be nonce'd, so the
        // nonce has to reach the template.
        $router = $this->sectionRouter(static fn (Request $r): PageView
            => new PageView('shop-index', ['items' => []], ['title' => 'Shop']));

        $response = $this->dispatch($router, '/shop');

        self::assertMatchesRegularExpression('/<style nonce="[^"]+">/', $response->body, 'a nonce-carrying style block');
        self::assertDoesNotMatchRegularExpression('/\sstyle\s*=\s*"/', $response->body, 'no inline style= from the section');
    }

    public function test_a_sub_path_reaches_the_resolver_as_a_product_page(): void
    {
        $router = $this->sectionRouter(static function (Request $r): ?PageView {
            if ($r->path === '/shop/milk') {
                return new PageView('shop-product', ['sku' => 'milk'], ['title' => 'Milk']);
            }
            return null;
        });

        $response = $this->dispatch($router, '/shop/milk');
        self::assertSame(200, $response->status);
        self::assertStringContainsString('<h1 class="product">milk</h1>', $response->body);
    }

    public function test_a_null_resolver_result_is_a_themed_404(): void
    {
        // A section owns its own not-found (an unknown SKU) — a themed 404, and
        // nothing that distinguishes "no such product" from "no such route".
        $router = $this->sectionRouter(static fn (Request $r): ?PageView => null);

        $response = $this->dispatch($router, '/shop/nope');
        self::assertSame(404, $response->status);
        self::assertStringContainsString('Powered by', $response->body, 'the themed 404, inside the layout');
    }
}

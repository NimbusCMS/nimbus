<?php

declare(strict_types=1);

namespace Nimbus\Tests\Unit;

use Nimbus\View\View;
use PHPUnit\Framework\TestCase;

/**
 * The template renderer's theme-facing surface: template resolution (with a
 * traversal guard) and the helpers injected into every template's scope.
 *
 * Rendered against the bundled starter theme, so these also guard that the
 * reference theme keeps the templates and partials it documents.
 */
final class ViewTest extends TestCase
{
    private function starter(): string
    {
        return dirname(__DIR__, 2) . '/themes/starter';
    }

    public function test_exists_finds_real_templates(): void
    {
        $view = new View($this->starter());

        self::assertTrue($view->exists('layout'));
        self::assertTrue($view->exists('entry'));
        self::assertTrue($view->exists('404'));
        self::assertFalse($view->exists('no-such-template'));
    }

    public function test_exists_rejects_path_traversal(): void
    {
        $view = new View($this->starter());

        // A template name derived from data must never walk out of the theme.
        self::assertFalse($view->exists('../../../etc/passwd'));
        self::assertFalse($view->exists('..'));
        self::assertFalse($view->exists('nested/../../escape'), 'a .. segment never resolves');
        self::assertFalse($view->exists('/etc/passwd'), 'an absolute path never resolves');
        self::assertFalse($view->exists('entry.php'), 'the .php is added by the renderer, not the caller');
    }

    public function test_the_escaper_helper_is_injected_into_templates(): void
    {
        // header.php uses the injected $e on $appName — no boilerplate of its own.
        $view = new View($this->starter(), ['appName' => 'Acme <x>']);

        $html = $view->partial('header');

        self::assertStringContainsString('Acme &lt;x&gt;', $html, 'appName escaped via injected $e');
    }

    public function test_the_layout_includes_partials_via_the_partial_helper(): void
    {
        $view = new View($this->starter(), ['appName' => 'Nimbus']);

        // Rendering any template wraps it in layout, which pulls in the header
        // and footer partials through the injected $partial helper.
        $html = $view->render('footer');

        self::assertStringContainsString('<header', $html, 'header partial composed by layout');
        self::assertStringContainsString('Powered by', $html, 'footer partial composed by layout');
    }

    public function test_the_header_renders_a_configured_menu(): void
    {
        $view = new View($this->starter(), ['appName' => 'Site', 'menus' => ['main' => [
            ['label' => 'Blog', 'url' => '/posts'],
            ['label' => 'About', 'url' => '/pages/about'],
        ]]]);

        $html = $view->partial('header');

        self::assertStringContainsString('href="/posts"', $html);
        self::assertStringContainsString('Blog', $html);
        self::assertStringContainsString('href="/pages/about"', $html);
        self::assertStringContainsString('About', $html);
    }

    public function test_the_header_has_no_nav_without_a_menu(): void
    {
        $view = new View($this->starter(), ['appName' => 'Site', 'menus' => []]);

        $html = $view->partial('header');

        self::assertStringNotContainsString('site-nav', $html, 'no menu, no nav element');
    }

    // ------------------------------------------ fallback templates (ADR 0023)

    /** Make a temp dir holding one template, returned as the fallback path. */
    private function fallbackWith(string $name, string $body): string
    {
        $dir = sys_get_temp_dir() . '/nimbus-fallback-' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir . '/' . $name . '.php', $body);
        return $dir;
    }

    public function test_a_fallback_template_renders_when_the_theme_lacks_it(): void
    {
        $dir  = $this->fallbackWith('shop-index', '<div class="shop">from plugin</div>');
        $view = (new View($this->starter(), ['appName' => 'Site', 'menus' => []]))->withFallback($dir);

        self::assertTrue($view->exists('shop-index'), 'resolved from the fallback dir');
        // Rendered inside the THEME layout (the layout is always the theme's).
        $html = $view->render('shop-index');
        self::assertStringContainsString('from plugin', $html, 'section body from the plugin default');
        self::assertStringContainsString('Powered by', $html, 'wrapped in the theme layout, not the plugin');

        @unlink($dir . '/shop-index.php');
        @rmdir($dir);
    }

    public function test_the_theme_wins_over_the_fallback(): void
    {
        // The fallback also provides `footer`, which the starter theme has — the
        // theme must win, so the fallback never overrides a template the theme ships.
        $dir  = $this->fallbackWith('footer', '<div>FALLBACK FOOTER should not appear</div>');
        $view = (new View($this->starter(), ['appName' => 'Site']))->withFallback($dir);

        self::assertTrue($view->exists('footer'));
        $html = $view->partial('footer');
        self::assertStringNotContainsString('FALLBACK FOOTER', $html, 'the theme template wins over the fallback');
        self::assertStringContainsString('Powered by', $html, 'the theme footer rendered, not the fallback');

        @unlink($dir . '/footer.php');
        @rmdir($dir);
    }

    public function test_the_fallback_obeys_the_same_traversal_guard(): void
    {
        $dir  = $this->fallbackWith('ok', 'x');
        $view = (new View($this->starter()))->withFallback($dir);

        // The name-safety charset applies before either dir is consulted — a
        // traversal name resolves in neither the theme nor the fallback.
        self::assertFalse($view->exists('../../../etc/passwd'));
        self::assertFalse($view->exists('../ok'), 'cannot climb into the fallback via ..');
        self::assertTrue($view->exists('ok'), 'a safe name in the fallback still resolves');

        @unlink($dir . '/ok.php');
        @rmdir($dir);
    }
}

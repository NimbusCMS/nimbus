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

        self::assertStringContainsString('<header>', $html, 'header partial composed by layout');
        self::assertStringContainsString('Powered by', $html, 'footer partial composed by layout');
    }
}

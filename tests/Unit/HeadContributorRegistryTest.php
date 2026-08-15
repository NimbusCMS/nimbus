<?php

declare(strict_types=1);

namespace Nimbus\Tests\Unit;

use Nimbus\Content\FieldTypeRegistry;
use Nimbus\Plugin\PluginContext;
use Nimbus\Site\HeadContributor;
use Nimbus\Site\HeadContributorRegistry;
use Nimbus\Site\PageContext;
use Nimbus\Support\EventDispatcher;
use PHPUnit\Framework\TestCase;

final class HeadContributorRegistryTest extends TestCase
{
    private function contributor(string $html): HeadContributor
    {
        return new class ($html) implements HeadContributor {
            public function __construct(private string $html)
            {
            }

            public function head(PageContext $page): string
            {
                return $this->html;
            }
        };
    }

    private function page(): PageContext
    {
        return new PageContext('entry', 'https://example.test/posts/hello', 'Hello', 'Site');
    }

    public function test_contributions_render_in_registration_order(): void
    {
        $registry = new HeadContributorRegistry();
        $registry->add($this->contributor('<a>'), 'p1');
        $registry->add($this->contributor('<b>'), 'p2');

        self::assertSame('<a><b>', $registry->render($this->page()));
    }

    public function test_forget_provider_removes_only_that_providers_contributions(): void
    {
        $registry = new HeadContributorRegistry();
        $registry->add($this->contributor('<a>'), 'p1');
        $registry->add($this->contributor('<b>'), 'p2');

        $registry->forgetProvider('p1');

        self::assertSame('<b>', $registry->render($this->page()));
    }

    public function test_a_throwing_contributor_is_isolated_from_the_page(): void
    {
        $registry = new HeadContributorRegistry();
        $registry->add(new class () implements HeadContributor {
            public function head(PageContext $page): string
            {
                throw new \RuntimeException('boom');
            }
        }, 'broken');
        $registry->add($this->contributor('<ok>'), 'good');

        self::assertSame('<ok>', $registry->render($this->page()), 'a broken contributor never breaks the page');
    }

    public function test_plugin_context_binds_contributions_to_the_plugin_id(): void
    {
        $registry = new HeadContributorRegistry();
        $context  = new PluginContext(new FieldTypeRegistry(), $registry, new EventDispatcher(), 'nimbuscms.seo');

        $context->head()->register($this->contributor('<x>'));
        self::assertSame('<x>', $registry->render($this->page()));

        // Rolling back that plugin id removes it — proving the binding.
        $registry->forgetProvider('nimbuscms.seo');
        self::assertSame('', $registry->render($this->page()));
    }
}

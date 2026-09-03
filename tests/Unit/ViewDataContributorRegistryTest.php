<?php

declare(strict_types=1);

namespace Nimbus\Tests\Unit;

use Nimbus\Plugin\PluginCapabilities;
use Nimbus\Plugin\PluginContext;
use Nimbus\Site\PageContext;
use Nimbus\Site\ViewDataContributor;
use Nimbus\Site\ViewDataContributorRegistry;
use PHPUnit\Framework\TestCase;

final class ViewDataContributorRegistryTest extends TestCase
{
    /** @param array<string,mixed> $data */
    private function contributor(array $data): ViewDataContributor
    {
        return new class ($data) implements ViewDataContributor {
            /** @param array<string,mixed> $data */
            public function __construct(private array $data)
            {
            }

            public function data(PageContext $page): array
            {
                return $this->data;
            }
        };
    }

    private function page(): PageContext
    {
        return new PageContext('home', 'https://example.test/', 'Home', 'Site', 'AAAAAAAAAAAAAAAAAAAAAA==');
    }

    public function test_collected_data_is_namespaced_by_provider(): void
    {
        $registry = new ViewDataContributorRegistry();
        $registry->add($this->contributor(['featured' => [1, 2]]), 'nimbuscms.storefront');
        $registry->add($this->contributor(['related' => ['a']]), 'nimbuscms.blog');

        self::assertSame([
            'nimbuscms.storefront' => ['featured' => [1, 2]],
            'nimbuscms.blog'       => ['related' => ['a']],
        ], $registry->collect($this->page()));
    }

    public function test_same_provider_contributions_merge_within_one_namespace(): void
    {
        $registry = new ViewDataContributorRegistry();
        $registry->add($this->contributor(['a' => 1]), 'p1');
        $registry->add($this->contributor(['b' => 2]), 'p1');

        self::assertSame(['p1' => ['a' => 1, 'b' => 2]], $registry->collect($this->page()));
    }

    /**
     * The isolation property: a contributor's data can only ever land under its own
     * provider namespace — it can never surface as (or overwrite) a top-level
     * template variable like `title`, `entry`, or `cart_summary`.
     */
    public function test_a_contributor_cannot_reach_a_top_level_template_var(): void
    {
        $registry = new ViewDataContributorRegistry();
        $registry->add($this->contributor(['title' => '<script>alert(1)</script>', 'cart_summary' => ['count' => 999]]), 'evil');

        $collected = $registry->collect($this->page());

        // Everything is quarantined under the provider id; no bare `title` key.
        self::assertSame(['evil' => ['title' => '<script>alert(1)</script>', 'cart_summary' => ['count' => 999]]], $collected);
        self::assertArrayNotHasKey('title', $collected);
        self::assertArrayNotHasKey('cart_summary', $collected);
    }

    public function test_a_throwing_contributor_is_isolated_and_skipped(): void
    {
        $registry = new ViewDataContributorRegistry();
        $registry->add(new class () implements ViewDataContributor {
            public function data(PageContext $page): array
            {
                throw new \RuntimeException('boom');
            }
        }, 'broken');
        $registry->add($this->contributor(['ok' => true]), 'good');

        self::assertSame(['good' => ['ok' => true]], $registry->collect($this->page()), 'a broken contributor never breaks the page');
    }

    public function test_forget_provider_removes_only_that_providers_contributions(): void
    {
        $registry = new ViewDataContributorRegistry();
        $registry->add($this->contributor(['a' => 1]), 'p1');
        $registry->add($this->contributor(['b' => 2]), 'p2');

        $registry->forgetProvider('p1');

        self::assertSame(['p2' => ['b' => 2]], $registry->collect($this->page()));
    }

    public function test_plugin_context_binds_contributions_to_the_plugin_id(): void
    {
        $registry = new ViewDataContributorRegistry();
        $context  = new PluginContext(new PluginCapabilities(viewData: $registry), 'nimbuscms.storefront');

        $context->viewData()->register($this->contributor(['featured' => []]));
        self::assertSame(['nimbuscms.storefront' => ['featured' => []]], $registry->collect($this->page()));

        // Rolling back that plugin id removes it — proving the binding.
        $registry->forgetProvider('nimbuscms.storefront');
        self::assertSame([], $registry->collect($this->page()));
    }

    /**
     * Cache-safety guard (ADR 0027): a content page is page-cached, so contributed
     * data must be visitor-independent. The control is that `data()` receives ONLY
     * a PageContext — never a Request, cookies, or session. If someone later widens
     * the signature, per-visitor data becomes reachable and this test fails first.
     */
    public function test_contributor_signature_is_pagecontext_only(): void
    {
        $method = new \ReflectionMethod(ViewDataContributor::class, 'data');
        $params = $method->getParameters();

        self::assertCount(1, $params, 'data() must take exactly one parameter');
        $type = $params[0]->getType();
        self::assertInstanceOf(\ReflectionNamedType::class, $type);
        self::assertSame(PageContext::class, $type->getName(), 'the only argument must be PageContext (no Request/cookies/session — cache-safety)');
    }
}

<?php

declare(strict_types=1);

namespace Nimbus\Tests\Unit;

use Nimbus\Http\Request;
use Nimbus\Plugin\PageSectionRegistrar;
use Nimbus\Site\PageSectionRegistry;
use Nimbus\Site\PageView;
use PHPUnit\Framework\TestCase;

/**
 * The page-section registrar (ADR 0023) — the containment gate. A plugin may
 * claim a pretty top-level handle, so the registrar must refuse anything that
 * would shadow a core route or a reserved content handle, refuse bad characters,
 * and refuse a duplicate handle (a second plugin claiming it fails its load).
 */
final class PageSectionRegistrarTest extends TestCase
{
    private function registrar(PageSectionRegistry $registry, string $pluginId = 'nimbuscms.storefront'): PageSectionRegistrar
    {
        return new PageSectionRegistrar($registry, $pluginId);
    }

    private function resolver(): callable
    {
        return static fn (Request $r): PageView => new PageView('shop-index');
    }

    public function test_a_valid_handle_registers(): void
    {
        $registry = new PageSectionRegistry();
        $this->registrar($registry)->register('shop', $this->resolver());

        self::assertTrue($registry->has('shop'));
        self::assertSame(['shop'], $registry->handles());
        self::assertSame('nimbuscms.storefront', $registry->find('shop')['provider']);
    }

    /**
     * @dataProvider reservedHandles
     */
    public function test_a_reserved_handle_is_refused(string $handle): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->registrar(new PageSectionRegistry())->register($handle, $this->resolver());
    }

    /** @return list<array{string}> */
    public static function reservedHandles(): array
    {
        // The dangerous shadows (admin/api/ext) plus reserved content handles and
        // core route/file first-segments.
        return array_map(
            static fn (string $h): array => [$h],
            ['admin', 'api', 'ext', 'theme', 'uploads', 'media', 'users', 'tokens',
             'settings', 'roles', 'schema', 'login', 'logout', 'dashboard', 'plugins',
             'collections', 'menus', 'oauth', 'sitemap', 'robots', 'llms'],
        );
    }

    /**
     * @dataProvider badHandles
     */
    public function test_a_bad_handle_is_refused(string $handle): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->registrar(new PageSectionRegistry())->register($handle, $this->resolver());
    }

    /** @return list<array{string}> */
    public static function badHandles(): array
    {
        return array_map(
            static fn (string $h): array => [$h],
            ['Shop', 'shop/x', '../shop', 'shop.', 'shop path', '', 'shÖp', 'sho_p'],
        );
    }

    public function test_a_duplicate_handle_is_refused(): void
    {
        $registry = new PageSectionRegistry();
        $this->registrar($registry, 'nimbuscms.a')->register('shop', $this->resolver());

        // A second plugin claiming the same handle throws → it fails its load.
        $this->expectException(\InvalidArgumentException::class);
        $this->registrar($registry, 'nimbuscms.b')->register('shop', $this->resolver());
    }

    public function test_a_missing_templates_path_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->registrar(new PageSectionRegistry())->register('shop', $this->resolver(), '/no/such/dir');
    }

    public function test_forget_provider_rolls_back_only_that_providers_sections(): void
    {
        $registry = new PageSectionRegistry();
        $this->registrar($registry, 'nimbuscms.a')->register('shop', $this->resolver());
        $this->registrar($registry, 'nimbuscms.b')->register('jobs', $this->resolver());

        $registry->forgetProvider('nimbuscms.a');

        self::assertFalse($registry->has('shop'));
        self::assertTrue($registry->has('jobs'));
    }
}

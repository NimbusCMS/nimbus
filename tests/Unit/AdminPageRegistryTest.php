<?php

declare(strict_types=1);

namespace Nimbus\Tests\Unit;

use InvalidArgumentException;
use Nimbus\Admin\AdminPageRegistry;
use Nimbus\Plugin\AdminPageRegistrar;
use Nimbus\Plugin\PluginCapabilities;
use Nimbus\Plugin\PluginContext;
use PHPUnit\Framework\TestCase;

final class AdminPageRegistryTest extends TestCase
{
    private function handler(): callable
    {
        return static fn (): string => 'x';
    }

    public function test_add_and_all_preserve_order(): void
    {
        $registry = new AdminPageRegistry();
        $registry->add('a', 'A', '★', $this->handler(), 'p1');
        $registry->add('b', 'B', '☆', $this->handler(), 'p2');

        self::assertSame(['a', 'b'], array_column($registry->all(), 'slug'));
    }

    public function test_forget_provider_removes_only_that_providers_pages(): void
    {
        $registry = new AdminPageRegistry();
        $registry->add('a', 'A', '★', $this->handler(), 'p1');
        $registry->add('b', 'B', '☆', $this->handler(), 'p2');

        $registry->forgetProvider('p1');

        self::assertSame(['b'], array_column($registry->all(), 'slug'));
    }

    public function test_the_registrar_accepts_a_valid_slug_and_binds_the_provider(): void
    {
        $registry = new AdminPageRegistry();
        (new AdminPageRegistrar($registry, 'nimbuscms.analytics'))
            ->register('analytics', 'Analytics', '📊', $this->handler());

        self::assertSame('analytics', $registry->all()[0]['slug']);
        self::assertSame('nimbuscms.analytics', $registry->all()[0]['provider']);
    }

    public function test_the_registrar_rejects_an_unsafe_slug(): void
    {
        $registrar = new AdminPageRegistrar(new AdminPageRegistry(), 'nimbuscms.analytics');

        $this->expectException(InvalidArgumentException::class);
        $registrar->register('Not A Slug!', 'x', 'x', $this->handler());
    }

    public function test_a_duplicate_slug_throws_naming_the_holder(): void
    {
        $registry = new AdminPageRegistry();
        $registry->add('reports', 'Reports', '★', $this->handler(), 'nimbuscms.analytics');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('nimbuscms.analytics');
        $registry->add('reports', 'Other', '☆', $this->handler(), 'other.plugin');
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('reservedSlugs')]
    public function test_the_registrar_rejects_a_core_reserved_slug(string $slug): void
    {
        $registrar = new AdminPageRegistrar(new AdminPageRegistry(), 'p');

        $this->expectException(InvalidArgumentException::class);
        $registrar->register($slug, 'X', '★', $this->handler());
    }

    /** @return array<string,array{string}> */
    public static function reservedSlugs(): array
    {
        return [
            'collections' => ['collections'],
            'users'       => ['users'],
            'settings'    => ['settings'],
            'plugins'     => ['plugins'],
            'dashboard'   => ['dashboard'],
            'login'       => ['login'],
            'oauth'       => ['oauth'],
        ];
    }

    public function test_a_page_defaults_to_no_capability(): void
    {
        $registry = new AdminPageRegistry();
        (new AdminPageRegistrar($registry, 'p'))->register('a', 'A', '★', $this->handler());

        self::assertNull($registry->all()[0]['capability'], 'default is login-only');
    }

    public function test_the_registrar_accepts_admin_and_management_capabilities(): void
    {
        $registry  = new AdminPageRegistry();
        $registrar = new AdminPageRegistrar($registry, 'p');

        $registrar->register('a', 'A', '★', $this->handler(), 'admin');
        $registrar->register('b', 'B', '☆', $this->handler(), 'users:write');

        self::assertSame(['admin', 'users:write'], array_column($registry->all(), 'capability'));
    }

    /**
     * A content-shaped cap would be reachable by the `*:read` wildcard, so it is
     * rejected at registration — the gate would not actually restrict the page.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('ungateableCapabilities')]
    public function test_the_registrar_rejects_a_content_or_malformed_capability(string $capability): void
    {
        $registrar = new AdminPageRegistrar(new AdminPageRegistry(), 'p');

        $this->expectException(InvalidArgumentException::class);
        $registrar->register('a', 'A', '★', $this->handler(), $capability);
    }

    /** @return array<string,array{string}> */
    public static function ungateableCapabilities(): array
    {
        return [
            'content resource'    => ['posts:read'],
            'unknown resource'    => ['analytics:read'],
            'bare wildcard'       => ['*:read'],
            'wrong action'        => ['users:delete'],
            'non-canonical case'  => ['Users:Write'],
            'padded'              => [' users:write '],
            'not a capability'    => ['nonsense'],
        ];
    }

    public function test_plugin_context_binds_pages_to_the_plugin_id(): void
    {
        $registry = new AdminPageRegistry();
        $context  = new PluginContext(new PluginCapabilities(adminPages: $registry), 'nimbuscms.analytics');

        $context->adminPages()->register('analytics', 'Analytics', '📊', $this->handler());
        self::assertSame('nimbuscms.analytics', $registry->all()[0]['provider']);

        $registry->forgetProvider('nimbuscms.analytics');
        self::assertSame([], $registry->all());
    }
}

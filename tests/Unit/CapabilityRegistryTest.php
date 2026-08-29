<?php

declare(strict_types=1);

namespace Nimbus\Tests\Unit;

use Nimbus\Auth\CapabilityRegistry;
use Nimbus\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The grantable plugin-management capabilities (ADR 0015, H2a). The registry's
 * one load-bearing rule — a namespaced resource — is what makes every collision
 * structurally impossible; these lock that in.
 */
final class CapabilityRegistryTest extends TestCase
{
    public function test_a_declared_capability_becomes_a_management_resource_and_grantable(): void
    {
        $reg = new CapabilityRegistry();
        $reg->declare('nimbuscms.inventory', 'Inventory', ['read', 'write']);

        self::assertSame(['nimbuscms.inventory'], $reg->managementResources());
        self::assertSame([
            'nimbuscms.inventory:read'  => 'Inventory: view',
            'nimbuscms.inventory:write' => 'Inventory: manage',
        ], $reg->grantable());
    }

    public function test_actions_are_independent_grants_like_core_management(): void
    {
        // Management read/write are independent (write does NOT imply read — that
        // implication is content-only), so a write-only declaration lists only
        // write; the admin grants each action explicitly.
        $reg = new CapabilityRegistry();
        $reg->declare('nimbuscms.inventory', 'Inventory', ['write']);

        self::assertSame(['nimbuscms.inventory:write' => 'Inventory: manage'], $reg->grantable());
    }

    public function test_a_declared_resource_can_never_be_a_collection_handle(): void
    {
        // THE structural guarantee closing FU-4: a namespaced (dotted) resource
        // folds under Str::handle() to something else, so no collection can ever
        // be named it — the management/content confusion cannot arise, in either
        // direction (a new collection, or a plugin installed onto existing ones).
        $reg = new CapabilityRegistry();
        $reg->declare('nimbuscms.inventory', 'Inventory', ['read', 'write']);

        foreach ($reg->managementResources() as $resource) {
            self::assertNotSame($resource, Str::handle($resource), "resource \"{$resource}\" must not be a valid collection handle");
        }
    }

    public function test_forget_provider_removes_the_declaration(): void
    {
        $reg = new CapabilityRegistry();
        $reg->declare('nimbuscms.inventory', 'Inventory', ['read', 'write']);
        $reg->forgetProvider('nimbuscms.inventory');

        self::assertSame([], $reg->managementResources());
        self::assertSame([], $reg->grantable());
    }

    public function test_a_flat_plugin_id_cannot_declare_a_capability(): void
    {
        // A flat id could equal a collection handle — refused, so the dot invariant
        // (and the collision guarantee that rests on it) holds for every resource.
        $reg = new CapabilityRegistry();
        $this->expectException(\InvalidArgumentException::class);
        $reg->declare('inventory', 'Inventory', ['read', 'write']);
    }

    public function test_a_capability_cannot_shadow_a_core_management_name(): void
    {
        $reg = new CapabilityRegistry();
        $this->expectException(\InvalidArgumentException::class);
        // Flat, and a core name — refused twice over.
        $reg->declare('media', 'Media', ['read', 'write']);
    }

    public function test_a_duplicate_declaration_is_refused(): void
    {
        $reg = new CapabilityRegistry();
        $reg->declare('nimbuscms.inventory', 'Inventory', ['read', 'write']);
        $this->expectException(\InvalidArgumentException::class);
        $reg->declare('nimbuscms.inventory', 'Inventory Again', ['read']);
    }

    #[DataProvider('badActions')]
    public function test_actions_must_be_a_nonempty_subset_of_read_write(mixed $actions): void
    {
        $reg = new CapabilityRegistry();
        $this->expectException(\InvalidArgumentException::class);
        /** @var list<string> $actions */
        $reg->declare('nimbuscms.inventory', 'Inventory', $actions);
    }

    /** @return array<string,array{list<string>}> */
    public static function badActions(): array
    {
        return [
            'empty'     => [[]],
            'unknown'   => [['delete']],
            'partly ok' => [['read', 'delete']],
            'admin'     => [['admin']],
        ];
    }

    #[DataProvider('badLabels')]
    public function test_label_must_be_1_to_80_chars(string $label): void
    {
        $reg = new CapabilityRegistry();
        $this->expectException(\InvalidArgumentException::class);
        $reg->declare('nimbuscms.inventory', $label, ['read']);
    }

    /** @return array<string,array{string}> */
    public static function badLabels(): array
    {
        return [
            'empty'      => [''],
            'whitespace' => ['   '],
            'too long'   => [str_repeat('x', 81)],
        ];
    }
}

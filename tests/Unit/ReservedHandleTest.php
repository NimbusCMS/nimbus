<?php

declare(strict_types=1);

namespace Nimbus\Tests\Unit;

use Nimbus\Auth\Authorizer;
use Nimbus\Content\CollectionService;
use PHPUnit\Framework\TestCase;

/**
 * FU-4 drift guard: PHP consts can't merge arrays, so the reserved
 * collection-handle set is a hand-written literal — this pins the invariant it
 * must uphold. Every management-capability name (plus `admin`) MUST be reserved,
 * or a collection named after a future seventh management capability would be
 * judged by Authorizer under management rules (a `foo:read` holder gaining
 * content-read of a collection named `foo`).
 */
final class ReservedHandleTest extends TestCase
{
    public function test_reserved_collection_handles_cover_every_management_capability(): void
    {
        $required = [...Authorizer::MANAGEMENT, 'admin'];

        foreach ($required as $name) {
            self::assertContains(
                $name,
                CollectionService::RESERVED_COLLECTION_HANDLES,
                "Management name \"{$name}\" must be a reserved collection handle (FU-4) — a collection with that handle would be judged under management authz rules.",
            );
        }
    }

    public function test_the_reserved_field_handles_are_the_built_in_entry_keys(): void
    {
        // These collide with the flat validation error map / entry shape (FU-6).
        self::assertSame(['title', 'slug', 'published_at'], CollectionService::RESERVED_FIELD_HANDLES);
    }
}

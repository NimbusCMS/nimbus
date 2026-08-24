<?php

declare(strict_types=1);

namespace Nimbus\Content;

use RuntimeException;

/**
 * A collection or field handle that would collide with a name the platform
 * reserves (FU-4 / FU-6).
 *
 * A **collection** handle in {@see CollectionService::RESERVED_COLLECTION_HANDLES}
 * would be judged by `Authorizer` under management rules (a `media:read` holder
 * gaining content-read of a collection named `media`) or shadowed by a built-in
 * route prefix. A **field** handle in {@see CollectionService::RESERVED_FIELD_HANDLES}
 * would collide with a built-in entry attribute in the flat validation error map.
 *
 * Thrown from the shared {@see CollectionService} chokepoint (create-time for a
 * collection handle; new-field-only on update, so a pre-existing colliding field
 * is grandfathered and never renamed out from under its stored values). Both
 * official surfaces catch it and render a friendly error, exactly as they catch
 * {@see DuplicateHandle}.
 */
final class ReservedHandle extends RuntimeException
{
    /** @param 'collection'|'field' $kind */
    public function __construct(public readonly string $handle, public readonly string $kind)
    {
        parent::__construct("Reserved {$kind} handle: {$handle}");
    }
}

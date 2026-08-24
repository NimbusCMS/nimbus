<?php

declare(strict_types=1);

namespace Nimbus\Content;

use Nimbus\Database\Connection;
use Nimbus\Support\Str;

/**
 * Transactional writes for a collection + its fields. Creating/updating a
 * collection and synchronizing its fields must succeed or fail together, so
 * they share one transaction boundary.
 */
final class CollectionService
{
    // Column widths (nb_collections / nb_fields) — validated at the input edge (admin
    // + MCP) so an over-long value is a friendly error, not a 1406 → 500. One source
    // for both surfaces; the DB stays the authority (boundary tests guard drift).
    public const HANDLE_MAX = 80;   // nb_collections.handle / nb_fields.handle VARCHAR(80)
    public const NAME_MAX   = 120;  // nb_collections.name VARCHAR(120)
    public const DESC_MAX   = 255;  // nb_collections.description VARCHAR(255)
    public const LABEL_MAX  = 120;  // nb_fields.label VARCHAR(120)

    /**
     * Handles a collection may not own (FU-4). The first seven collide with a
     * permission-capability name, so `Authorizer` would judge the collection
     * under management rules (a `media:read` holder gaining content-read of a
     * collection named `media`); the rest are built-in public route prefixes
     * that would shadow the collection's own pages. Kept a superset of
     * `Authorizer::MANAGEMENT ∪ {'admin'}` by a drift-guard test (PHP consts
     * can't merge arrays, so the invariant is asserted, not computed).
     */
    public const RESERVED_COLLECTION_HANDLES = [
        'schema', 'media', 'users', 'tokens', 'settings', 'roles', 'admin',
        'api', 'uploads', 'theme',
    ];

    /** Field handles that collide with a built-in entry attribute in the flat
     *  validation error map / entry shape (FU-6). */
    public const RESERVED_FIELD_HANDLES = ['title', 'slug', 'published_at'];

    public function __construct(
        private Connection $db,
        private CollectionRepository $collections,
    ) {
    }

    /**
     * @param array<string,mixed> $options
     * @param array<int,FieldDef> $fieldDefs
     */
    public function create(string $handle, string $name, string $icon, string $description, array $options, array $fieldDefs): int
    {
        // Reserved-name guard (FU-4/FU-6). Normalize here too so a caller that
        // skipped Str::handle (e.g. a seeder) can't slip a reserved name past.
        if (in_array(Str::handle($handle), self::RESERVED_COLLECTION_HANDLES, true)) {
            throw new ReservedHandle($handle, 'collection');
        }
        foreach ($fieldDefs as $def) {
            if (in_array(Str::handle((string) $def['handle']), self::RESERVED_FIELD_HANDLES, true)) {
                throw new ReservedHandle((string) $def['handle'], 'field');
            }
        }

        try {
            return $this->db->transaction(function () use ($handle, $name, $icon, $description, $options, $fieldDefs): int {
                $id = $this->collections->create($handle, $name, $icon, $description, $options);
                $this->collections->syncFields($id, $fieldDefs);
                return $id;
            });
        } catch (\PDOException $e) {
            // The unique index is the authority — a read-then-write check would
            // still let two concurrent creates through.
            if (Connection::isDuplicateKey($e)) {
                throw new DuplicateHandle($handle, $e);
            }
            throw $e;
        }
    }

    /**
     * @param array<string,mixed> $options
     * @param array<int,FieldDef> $fieldDefs
     */
    public function update(int $id, string $name, string $icon, string $description, array $options, array $fieldDefs): void
    {
        // New-field-only reserved guard (FU-6): a reserved field handle that is
        // already stored is grandfathered — rejecting it would force syncFields
        // (which matches by handle) into a data-lossy DELETE+INSERT rename. Only
        // a field handle NOT already on the collection is checked. The collection
        // handle is immutable on update, so it is never re-checked here.
        $existing = [];
        $stored   = $this->collections->find($id);
        if ($stored !== null) {
            foreach ($stored->fields as $field) {
                $existing[$field->handle] = true;
            }
        }
        foreach ($fieldDefs as $def) {
            $handle = (string) $def['handle'];
            if (!isset($existing[$handle]) && in_array(Str::handle($handle), self::RESERVED_FIELD_HANDLES, true)) {
                throw new ReservedHandle($handle, 'field');
            }
        }

        $this->db->transaction(function () use ($id, $name, $icon, $description, $options, $fieldDefs): void {
            $this->collections->update($id, $name, $icon, $description, $options);
            $this->collections->syncFields($id, $fieldDefs);
        });
    }

    /**
     * @throws CollectionInUse when a relation field in another collection still
     *         targets this one — deleting it would leave that field dangling.
     */
    public function delete(int $id): void
    {
        // Check + delete in one transaction: a delete that a concurrent
        // field-write races is an operator conflict (same as MediaInUse), and
        // the guard runs post-authz on every surface (the shared chokepoint).
        $this->db->transaction(function () use ($id): void {
            $collection = $this->collections->find($id);
            if ($collection !== null) {
                $targeters = $this->collections->relationFieldsTargeting($collection->handle, $id);
                if ($targeters !== []) {
                    throw new CollectionInUse($collection->handle, $targeters);
                }
            }
            // Field/entry/relation rows cascade via FKs.
            $this->collections->delete($id);
        });
    }
}

<?php

declare(strict_types=1);

namespace Nimbus\Auth;

use Nimbus\Content\CollectionRepository;
use Nimbus\Database\Connection;

/**
 * Seeds the three system roles and assigns existing users, reproducing today's
 * behavior exactly (ADR 0011):
 *
 * - `admin` → the `admin` super-grant.
 * - `editor` / `author` → `*:read` (they browse every collection today) plus
 *   `{handle}:write` for each collection whose manage-list named them.
 *
 * Then each existing user is assigned the system role matching their legacy
 * `users.role`, so the union of their roles equals the single role they had —
 * no one's access changes. Idempotent: existing roles are not overwritten (an
 * admin may have edited them) and assignments dedupe on the composite key.
 */
final class RoleSeeder
{
    public function __construct(
        private Connection $db,
        private RoleRepository $roles,
        private CollectionRepository $collections,
    ) {
    }

    /** @return int the number of system roles created this run */
    public function seed(): int
    {
        $created = 0;
        $ids     = [];
        foreach ($this->capabilityBundles() as $name => $capabilities) {
            $existing = $this->roles->findByName($name);
            if ($existing !== null) {
                $ids[$name] = $existing->id;
                continue;
            }
            $ids[$name] = $this->roles->create($name, $capabilities, true);
            $created++;
        }

        foreach ($this->db->select('SELECT id, role FROM nb_users') as $user) {
            $roleId = $ids[(string) $user['role']] ?? null;
            if ($roleId !== null) {
                $this->roles->assignToUser((int) $user['id'], $roleId);
            }
        }

        return $created;
    }

    /**
     * The seeded capabilities per system role — editor/author fold in the
     * collections whose manage-lists name them.
     *
     * @return array<string,list<string>>
     */
    private function capabilityBundles(): array
    {
        $writes = ['editor' => [], 'author' => []];
        foreach ($this->collections->all() as $collection) {
            foreach ($collection->managerRoles() as $role) {
                if (isset($writes[$role])) {
                    $writes[$role][] = $collection->handle . ':write';
                }
            }
        }

        return [
            'admin'  => ['admin'],
            'editor' => array_values(array_unique(array_merge(['*:read'], $writes['editor']))),
            'author' => array_values(array_unique(array_merge(['*:read'], $writes['author']))),
        ];
    }
}

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
 *   `media:read`+`media:write` (the media library was open to all pre-Slice-3b)
 *   plus `{handle}:write` for each collection whose manage-list named them.
 *
 * Then each existing user **that has no role assignments yet** is assigned the
 * system role matching their legacy `users.role`, so the union of their roles
 * equals the single role they had — no one's access changes. Idempotent, and
 * **never widening**: existing roles are not overwritten (an admin may have
 * edited them), and a user who already holds any role is skipped, so a re-run
 * can never add authority past an admin's decision (FU-1). A user with zero
 * roles is treated as "never seeded" and re-acquires the legacy role on a
 * re-run — the least-privilege content role for the common placeholder case.
 */
final class RoleSeeder
{
    /**
     * Media caps every content role gets. Before ADR 0011's Slice 3b the media
     * library was open to any signed-in user; gating it on `media:*` would lock
     * editor/author out, so they are seeded with both. Management caps grant no
     * read↔write implication, so both are needed. Migration 012 backfills the
     * same pair into already-seeded installs — keep the two in lockstep (asserted
     * by RoleSeederTest).
     *
     * @var list<string>
     */
    public const CONTENT_MEDIA_CAPS = ['media:read', 'media:write'];

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
            // FU-1: seed the legacy-`role`-derived system role ONLY for a user
            // that has no assignments yet (first boot, or a `create-user` CLI
            // user). A user who already holds any nb_user_roles row has an
            // authority set an admin curated — a re-run of this idempotent
            // seeder must never *widen* it (a placeholder user gaining `author`
            // caps, or a demoted legacy admin regaining `admin`).
            if ($this->roles->hasAnyRole((int) $user['id'])) {
                continue;
            }
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
            'editor' => array_values(array_unique(array_merge(['*:read'], self::CONTENT_MEDIA_CAPS, $writes['editor']))),
            'author' => array_values(array_unique(array_merge(['*:read'], self::CONTENT_MEDIA_CAPS, $writes['author']))),
        ];
    }
}

<?php

declare(strict_types=1);

namespace Nimbus\Tests\Integration;

use Nimbus\Auth\RoleRepository;
use Nimbus\Auth\RoleSeeder;
use Nimbus\Content\CollectionRepository;

/**
 * The system-role seed and the Slice-3b media backfill (ADR 0011).
 *
 * Two paths must agree: a fresh install gets media caps from RoleSeeder, and an
 * already-seeded install gets them from migration 012 — the repo's first data
 * migration, which must be additive and idempotent and never touch a custom role.
 */
final class RoleSeederTest extends IntegrationTestCase
{
    private RoleRepository $roles;

    protected function setUp(): void
    {
        parent::setUp();
        $this->roles = new RoleRepository($this->db);
    }

    private function seeder(): RoleSeeder
    {
        return new RoleSeeder($this->db, $this->roles, new CollectionRepository($this->db));
    }

    /** The exact SQL the real migration ships — loaded, not copied. */
    private function runMediaBackfill(): void
    {
        /** @var list<string> $statements */
        $statements = require \Nimbus\Support\Config::basePath() . '/src/Database/migrations/012_role_media_caps.php';
        foreach ($statements as $sql) {
            $this->db->execute($sql);
        }
    }

    /**
     * Capabilities of an existing role by id, asserting it is present.
     *
     * @return list<string>
     */
    private function caps(int $id): array
    {
        $role = $this->roles->find($id);
        self::assertNotNull($role);
        return $role->capabilities;
    }

    /**
     * Capabilities of an existing role by name, asserting it is present.
     *
     * @return list<string>
     */
    private function capsByName(string $name): array
    {
        $role = $this->roles->findByName($name);
        self::assertNotNull($role, "role {$name} should exist");
        return $role->capabilities;
    }

    // ------------------------------------------------------- fresh-install seed

    public function test_seed_grants_media_to_content_roles(): void
    {
        $this->seeder()->seed();

        foreach (['editor', 'author'] as $name) {
            $caps = $this->capsByName($name);
            self::assertContains('media:read', $caps, "{$name} can read media");
            self::assertContains('media:write', $caps, "{$name} can write media");
            self::assertContains('*:read', $caps, "{$name} keeps its content read");
        }
        // admin needs no explicit media cap — the super-grant covers it.
        self::assertSame(['admin'], $this->capsByName('admin'));
    }

    // ------------------------------------------------------ existing-install backfill

    public function test_backfill_adds_media_to_pre_slice3b_system_roles(): void
    {
        // Simulate a pre-3b install: system editor/author seeded with *:read only.
        $editor = $this->roles->create('editor', ['*:read'], true);
        $this->roles->create('author', ['*:read'], true);

        $this->runMediaBackfill();

        $caps = $this->caps($editor);
        self::assertSame(['*:read', 'media:read', 'media:write'], $caps, 'media caps appended, order preserved, nothing lost');
    }

    public function test_backfill_is_idempotent(): void
    {
        $editor = $this->roles->create('editor', ['*:read'], true);

        $this->runMediaBackfill();
        $this->runMediaBackfill(); // a re-run must not duplicate

        $caps = $this->caps($editor);
        self::assertSame(['media:read'], array_values(array_filter($caps, static fn (string $c): bool => $c === 'media:read')), 'media:read appears exactly once');
        self::assertSame(['media:write'], array_values(array_filter($caps, static fn (string $c): bool => $c === 'media:write')), 'media:write appears exactly once');
    }

    public function test_backfill_leaves_custom_and_admin_roles_untouched(): void
    {
        $custom = $this->roles->create('contributor', ['posts:write'], false); // is_system = 0
        $admin  = $this->roles->create('admin', ['admin'], true);

        $this->runMediaBackfill();

        self::assertSame(['posts:write'], $this->caps($custom), 'a custom role is never touched');
        self::assertSame(['admin'], $this->caps($admin), 'admin is never touched');
    }

    public function test_backfill_on_an_empty_install_is_a_no_op(): void
    {
        // Fresh install: migrate runs before the seed, so nb_roles is empty.
        $this->runMediaBackfill();

        self::assertSame([], $this->roles->all(), 'nothing to backfill, nothing created');
    }

    // ------------------------------------------------------------- parity

    public function test_seed_and_backfill_grant_the_same_media_caps(): void
    {
        // The two paths must not drift: RoleSeeder's constant is the single source.
        self::assertSame(['media:read', 'media:write'], RoleSeeder::CONTENT_MEDIA_CAPS);

        $editor = $this->roles->create('editor', ['*:read'], true);
        $this->runMediaBackfill();
        $backfilled = array_values(array_diff($this->caps($editor), ['*:read']));

        self::assertSame(RoleSeeder::CONTENT_MEDIA_CAPS, $backfilled, 'the migration adds exactly the seeder constant');
    }
}

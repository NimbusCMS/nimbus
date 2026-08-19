<?php

declare(strict_types=1);

namespace Nimbus\Tests\Integration;

use Nimbus\Auth\RoleRepository;
use Nimbus\Auth\RoleSeeder;
use Nimbus\Auth\UserPrincipal;
use Nimbus\Content\CollectionRepository;
use Nimbus\Content\CollectionService;

/**
 * The roles store, the behavior-preserving seed, and the union-of-roles
 * authorization (ADR 0011, Slice 1). Enforcement is untouched in this slice —
 * these prove the machinery is correct before Slice 3 wires it in.
 */
final class RolesTest extends IntegrationTestCase
{
    private RoleRepository $roles;
    private CollectionRepository $collections;

    protected function setUp(): void
    {
        parent::setUp();
        $this->roles       = new RoleRepository($this->db);
        $this->collections = new CollectionRepository($this->db);
    }

    /** @param list<string> $manage roles allowed to manage the collection */
    private function makeCollection(string $handle, array $manage = []): void
    {
        (new CollectionService($this->db, $this->collections))->create(
            $handle,
            ucfirst($handle),
            '#',
            '',
            ['kind' => 'collection', 'permissions' => ['manage' => $manage]],
            [],
        );
    }

    private function makeUser(string $email, string $role): int
    {
        $now = date('Y-m-d H:i:s');
        return $this->db->insert(
            'INSERT INTO nb_users (name, email, password, role, created_at, updated_at) VALUES (:n, :e, :p, :r, :c, :u)',
            ['n' => 'U', 'e' => $email, 'p' => 'x', 'r' => $role, 'c' => $now, 'u' => $now],
        );
    }

    private function seed(): void
    {
        (new RoleSeeder($this->db, $this->roles, $this->collections))->seed();
    }

    private function principal(int $userId): UserPrincipal
    {
        return new UserPrincipal($userId, $this->roles->capabilitiesForUser($userId));
    }

    public function test_seed_creates_the_three_system_roles(): void
    {
        $this->seed();
        foreach (['admin', 'editor', 'author'] as $name) {
            $role = $this->roles->findByName($name);
            self::assertNotNull($role, "the {$name} role");
            self::assertTrue($role->isSystem);
        }
    }

    public function test_editor_seed_reads_all_and_folds_its_manage_lists(): void
    {
        $this->makeCollection('posts', ['editor']);
        $this->makeCollection('pages', ['author']);
        $this->seed();

        $editor = $this->roles->findByName('editor');
        self::assertNotNull($editor);
        self::assertContains('*:read', $editor->capabilities, 'editors browse every collection today');
        self::assertContains('posts:write', $editor->capabilities, 'posts listed editor');
        self::assertNotContains('pages:write', $editor->capabilities, 'pages listed author, not editor');
    }

    public function test_a_migrated_editor_reads_all_but_writes_only_granted_collections(): void
    {
        $this->makeCollection('posts', ['editor']);
        $this->makeCollection('secret', []); // manages nobody
        $user = $this->makeUser('ed@site.test', 'editor');
        $this->seed();

        $p = $this->principal($user);
        self::assertTrue($p->can('posts', 'read'));
        self::assertTrue($p->can('posts', 'write'), 'folded from the manage-list');
        self::assertTrue($p->can('secret', 'read'), 'still browses every collection (*:read)');
        self::assertFalse($p->can('secret', 'write'), 'but cannot manage one that did not grant it');
        self::assertFalse($p->can('schema', 'write'), 'an editor is not an admin');
    }

    public function test_an_admin_user_gets_the_super_grant(): void
    {
        $user = $this->makeUser('boss@site.test', 'admin');
        $this->seed();

        $p = $this->principal($user);
        self::assertTrue($p->can('schema', 'write'));
        self::assertTrue($p->can('users', 'write'));
        self::assertTrue($p->can('anything', 'write'));
    }

    public function test_capabilities_are_the_union_of_multiple_roles(): void
    {
        $this->makeCollection('posts', ['editor']);
        $user = $this->makeUser('multi@site.test', 'editor');
        $this->seed(); // assigns the editor role

        $mediaRole = $this->roles->create('Media manager', ['media:read', 'media:write']);
        $this->roles->assignToUser($user, $mediaRole);

        $p = $this->principal($user);
        self::assertTrue($p->can('posts', 'write'), 'from the editor role');
        self::assertTrue($p->can('media', 'write'), 'from the added media role');
        self::assertCount(2, $this->roles->rolesForUser($user));
    }

    public function test_seed_is_idempotent(): void
    {
        $user = $this->makeUser('a@site.test', 'admin');
        $this->seed();
        $roleCount = count($this->roles->all());
        $this->seed();

        self::assertSame($roleCount, count($this->roles->all()), 're-seeding creates no duplicate roles');
        self::assertCount(1, $this->roles->rolesForUser($user), 'no duplicate assignment');
    }
}

<?php

declare(strict_types=1);

namespace Nimbus\Tests\Http;

use Nimbus\Auth\RoleRepository;
use Nimbus\Auth\UserRepository;

/**
 * The users admin page (ADR 0011, Slice 2): create people, assign them roles,
 * and never strand the install without an admin.
 */
final class UsersAdminTest extends HttpTestCase
{
    private RoleRepository $roles;
    private UserRepository $users;

    protected function setUp(): void
    {
        parent::setUp();
        $this->roles = new RoleRepository($this->db);
        $this->users = new UserRepository($this->db);
    }

    public function test_admin_creates_a_user_and_assigns_roles(): void
    {
        $this->actingAs('admin');
        $editor = $this->roles->create('Editor', ['*:read'], true);

        $response = $this->post('/admin/users', ['email' => 'new@site.test', 'name' => 'New', 'password' => 'good-passphrase-1', 'roles' => [$editor]]);
        self::assertSame(302, $response->status);

        $user = $this->users->findByEmail('new@site.test');
        self::assertNotNull($user);
        $assigned = $this->roles->rolesForUser($user->id);
        self::assertCount(1, $assigned);
        self::assertSame('Editor', $assigned[0]->name);
    }

    public function test_weak_password_and_duplicate_email_are_rejected(): void
    {
        $this->actingAs('admin');

        $this->post('/admin/users', ['email' => 'weak@site.test', 'password' => 'short']);
        self::assertNull($this->users->findByEmail('weak@site.test'), 'weak password rejected');

        $this->post('/admin/users', ['email' => 'dup@site.test', 'password' => 'good-passphrase-1']);
        $this->post('/admin/users', ['email' => 'dup@site.test', 'password' => 'good-passphrase-1']);
        self::assertCount(1, array_filter($this->users->all(), static fn ($u): bool => $u->email === 'dup@site.test'));
    }

    public function test_editing_replaces_the_users_roles(): void
    {
        $this->actingAs('admin');
        $roleA = $this->roles->create('A', ['posts:read'], false);
        $roleB = $this->roles->create('B', ['pages:read'], false);
        $userId = $this->users->create('Ed', 'ed@site.test', 'x', 'author');
        $this->roles->assignToUser($userId, $roleA);

        $this->post('/admin/users/' . $userId, ['roles' => [$roleB]]);

        $names = array_map(static fn ($r): string => $r->name, $this->roles->rolesForUser($userId));
        self::assertSame(['B'], $names, 'roles are synced to exactly the submitted set');
    }

    public function test_the_last_admin_cannot_lose_the_admin_role(): void
    {
        // The acting admin is the only admin (actingAs assigns the admin role).
        $only           = $this->actingAs('admin');
        $adminRoleModel = $this->roles->findByName('admin');
        self::assertNotNull($adminRoleModel);
        $adminRole = $adminRoleModel->id;
        $editor    = $this->roles->create('Editor', ['*:read'], true);

        // Try to demote the only admin (self).
        $this->post('/admin/users/' . $only, ['roles' => [$editor]]);
        $names = array_map(static fn ($r): string => $r->name, $this->roles->rolesForUser($only));
        self::assertContains('admin', $names, 'the only admin keeps the admin role');

        // With a second admin, the demotion is allowed.
        $second = $this->users->create('Deputy', 'deputy@site.test', 'x', 'admin');
        $this->roles->assignToUser($second, $adminRole);
        $this->post('/admin/users/' . $only, ['roles' => [$editor]]);
        self::assertNotContains('admin', array_map(static fn ($r): string => $r->name, $this->roles->rolesForUser($only)));
    }
}

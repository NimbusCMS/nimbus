<?php

declare(strict_types=1);

namespace Nimbus\Tests\Http;

use Nimbus\Auth\RoleRepository;

/**
 * The roles admin page (ADR 0011, Slice 2): an admin composes named capability
 * bundles. Capabilities are validated against the known set; built-in roles are
 * protected.
 */
final class RolesAdminTest extends HttpTestCase
{
    private RoleRepository $roles;

    protected function setUp(): void
    {
        parent::setUp();
        $this->roles = new RoleRepository($this->db);
    }

    public function test_admin_creates_a_role_with_capabilities(): void
    {
        $this->actingAs('admin');
        $this->makeCollection('posts');

        $response = $this->post('/admin/roles', ['name' => 'Blog editor', 'caps' => ['posts:write', 'media:read']]);
        self::assertSame(302, $response->status);

        $role = $this->roles->findByName('Blog editor');
        self::assertNotNull($role);
        self::assertEqualsCanonicalizing(['posts:write', 'media:read'], $role->capabilities);
        self::assertFalse($role->isSystem);
    }

    public function test_unknown_capabilities_are_dropped(): void
    {
        $this->actingAs('admin');
        $this->makeCollection('posts');

        $this->post('/admin/roles', ['name' => 'Odd', 'caps' => ['posts:write', 'ghost:write', 'sudo', 'admin']]);

        $role = $this->roles->findByName('Odd');
        self::assertNotNull($role);
        self::assertEqualsCanonicalizing(['posts:write', 'admin'], $role->capabilities, 'only real capabilities are stored');
    }

    public function test_editing_a_custom_role_updates_its_capabilities(): void
    {
        $this->actingAs('admin');
        $this->makeCollection('posts');
        $id = $this->roles->create('Team', ['posts:read'], false);

        $this->post('/admin/roles/' . $id, ['name' => 'Team', 'caps' => ['posts:write']]);

        self::assertSame(['posts:write'], $this->roles->find($id)?->capabilities);
    }

    public function test_the_admin_role_cannot_be_edited(): void
    {
        $this->actingAs('admin');
        $id = $this->roles->create('admin', ['admin'], true);

        $this->post('/admin/roles/' . $id, ['name' => 'admin', 'caps' => []]);

        self::assertSame(['admin'], $this->roles->find($id)?->capabilities, 'the super-grant is left intact');
    }

    public function test_built_in_roles_cannot_be_deleted_but_custom_ones_can(): void
    {
        $this->actingAs('admin');
        $system = $this->roles->create('editor', ['*:read'], true);
        $custom = $this->roles->create('Temp', [], false);

        $this->post('/admin/roles/' . $system . '/delete', []);
        self::assertNotNull($this->roles->find($system), 'a built-in role survives');

        $this->post('/admin/roles/' . $custom . '/delete', []);
        self::assertNull($this->roles->find($custom), 'a custom role is deleted');
    }
}

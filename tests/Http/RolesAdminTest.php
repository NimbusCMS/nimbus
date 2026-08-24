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
        $admin = $this->roles->findByName('admin');
        self::assertNotNull($admin);

        $this->post('/admin/roles/' . $admin->id, ['name' => 'admin', 'caps' => []]);

        self::assertSame(['admin'], $this->roles->find($admin->id)?->capabilities, 'the super-grant is left intact');
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

    // ------------------------------------------------ subset-only on delete (ADMIN-3)

    public function test_a_roles_write_only_actor_cannot_delete_a_superior_role(): void
    {
        // A custom role that grants more than the actor holds.
        $superior = $this->roles->create('Ops', ['users:write', 'schema:write'], false);
        // The actor can manage roles but holds neither users:write nor schema:write.
        $this->actingWithCapabilities(['roles:write']);

        $response = $this->post('/admin/roles/' . $superior . '/delete', []);

        self::assertSame(302, $response->status);
        self::assertStringContainsString('err=', (string) $response->header('Location'));
        self::assertNotNull($this->roles->find($superior), 'the superior role must survive — delete is subset-guarded like edit');
    }

    public function test_a_blocked_delete_leaves_a_role_bound_tokens_capabilities_intact(): void
    {
        $tokens   = new \Nimbus\Api\ApiTokenRepository($this->db);
        $superior = $this->roles->create('Ops', ['users:write'], false);
        $plain    = $tokens->create('Ops token', [], null, $superior); // bound to the role, no explicit abilities

        $this->actingWithCapabilities(['roles:write']);
        $this->post('/admin/roles/' . $superior . '/delete', []);

        $token = $tokens->findByPlaintext($plain);
        self::assertNotNull($token);
        /** @var \Nimbus\Api\ApiToken $token */
        self::assertContains('users:write', $tokens->principalFor($token)->scopes, 'the blocked delete did not blind the role-bound token');
    }

    public function test_an_admin_can_delete_a_role_that_grants_anything(): void
    {
        $this->actingAs('admin'); // admin holds everything → subset guard always passes
        $superior = $this->roles->create('Ops', ['users:write', 'schema:write'], false);

        $this->post('/admin/roles/' . $superior . '/delete', []);

        self::assertNull($this->roles->find($superior), 'an admin can still delete any custom role');
    }
}

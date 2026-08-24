<?php

declare(strict_types=1);

namespace Nimbus\Tests\Http;

use Nimbus\Auth\RoleRepository;
use Nimbus\Auth\UserRepository;

/**
 * The capability enforcement flip (ADR 0011, Slice 3) — the security core. Proves
 * the authorization matrix (deny-without / allow-with), the two escalation
 * blockers (subset-only on role save AND on user role-assignment), and the
 * un-seeded legacy fallback.
 */
final class RolesEnforcementTest extends HttpTestCase
{
    private RoleRepository $roles;
    private UserRepository $users;

    protected function setUp(): void
    {
        parent::setUp();
        $this->roles = new RoleRepository($this->db);
        $this->users = new UserRepository($this->db);
    }

    // ---------------------------------------------------- authorization matrix

    public function test_schema_write_gates_the_collection_form(): void
    {
        $this->actingWithCapabilities(['posts:write']); // content only, no schema
        self::assertSame(302, $this->get('/admin/collections/new')->status, 'no schema:write → denied');

        $this->actingWithCapabilities(['schema:write']);
        self::assertSame(200, $this->get('/admin/collections/new')->status, 'schema:write → allowed');
    }

    public function test_management_caps_gate_their_sections(): void
    {
        $this->actingWithCapabilities(['posts:write']);
        self::assertSame(302, $this->get('/admin/tokens')->status, 'no tokens:write');
        self::assertSame(302, $this->get('/admin/users')->status, 'no users:write');
        self::assertSame(302, $this->get('/admin/roles')->status, 'no roles:write');

        $this->actingWithCapabilities(['tokens:write']);
        self::assertSame(200, $this->get('/admin/tokens')->status, 'tokens:write → allowed');
    }

    public function test_the_dashboard_shows_no_dead_links_to_gated_sections(): void
    {
        // A content-only user's dashboard must not link to media or users — the
        // handlers already gate them, so a visible card would be a dead link.
        $this->actingWithCapabilities(['posts:write']);
        $body = $this->get('/admin')->body;

        self::assertStringNotContainsString('/admin/media', $body, 'no media card without media:read');
        self::assertStringNotContainsString('/admin/users', $body, 'no users card without users:write');

        // An admin sees both.
        $this->actingAs('admin');
        $adminBody = $this->get('/admin')->body;
        self::assertStringContainsString('/admin/media', $adminBody);
        self::assertStringContainsString('/admin/users', $adminBody);
    }

    public function test_content_write_gates_entry_management(): void
    {
        $this->makeCollection('posts');
        // A user who can write pages but not posts.
        $this->makeCollection('pages');
        $this->actingWithCapabilities(['pages:write']);

        self::assertSame(302, $this->get('/admin/collections/posts/entries/new')->status, 'cannot manage posts');
        self::assertSame(200, $this->get('/admin/collections/pages/entries/new')->status, 'can manage pages');
    }

    // ------------------------------------------------------ escalation blockers

    public function test_a_role_manager_cannot_grant_a_capability_it_lacks(): void
    {
        // Holds roles:write but not admin/schema:write.
        $this->actingWithCapabilities(['roles:write']);

        $this->post('/admin/roles', ['name' => 'Sneaky', 'caps' => ['admin']]);
        self::assertNull($this->roles->findByName('Sneaky'), 'cannot mint an admin-granting role');

        $this->post('/admin/roles', ['name' => 'Sneaky2', 'caps' => ['schema:write']]);
        self::assertNull($this->roles->findByName('Sneaky2'), 'cannot grant a capability it does not hold');
    }

    public function test_a_user_manager_cannot_assign_a_role_beyond_itself(): void
    {
        $adminRole = $this->roles->create('admin', ['admin'], true);
        // Holds users:write but not admin.
        $this->actingWithCapabilities(['users:write']);

        $this->post('/admin/users', ['email' => 'victim@site.test', 'password' => 'good-passphrase-1', 'roles' => [$adminRole]]);

        // Whether the create is refused or lands without the role, the victim must
        // never carry admin — no escalation via assignment.
        $victim    = $this->users->findByEmail('victim@site.test');
        $roleNames = $victim === null ? [] : array_map(static fn ($r): string => $r->name, $this->roles->rolesForUser($victim->id));
        self::assertNotContains('admin', $roleNames, 'a users:write actor cannot assign the admin role');
    }

    public function test_an_admin_may_grant_and_assign_freely(): void
    {
        $this->actingAs('admin');
        $this->post('/admin/roles', ['name' => 'Power', 'caps' => ['schema:write', 'admin']]);
        self::assertNotNull($this->roles->findByName('Power'), 'admin holds everything, so may grant anything');
    }

    // -------------------------------------------------------- un-seeded fallback

    public function test_unseeded_install_authorizes_via_the_legacy_path(): void
    {
        // No roles seeded → the Gate delegates to legacy Permissions verbatim.
        self::assertFalse($this->roles->hasAny());

        $this->actingAsLegacy('admin');
        self::assertSame(200, $this->get('/admin/collections/new')->status, 'legacy admin reaches the schema form');

        $this->actingAsLegacy('editor');
        self::assertSame(302, $this->get('/admin/collections/new')->status, 'legacy editor is denied, exactly as before');
        self::assertFalse($this->roles->hasAny(), 'and nothing seeded roles behind our back');
    }
}

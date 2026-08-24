<?php

declare(strict_types=1);

namespace Nimbus\Tests\Http;

use Nimbus\Api\ApiTokenRepository;
use Nimbus\Http\FormNonce;

/**
 * The token admin surface, through the real kernel.
 *
 * The security-critical properties: it is administrator-only, every write is
 * CSRF-guarded, and the freshly-minted secret is shown exactly once — on the
 * mint response, never in a redirect or a later page load.
 */
final class TokenAdminTest extends HttpTestCase
{
    private ApiTokenRepository $tokens;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tokens = new ApiTokenRepository($this->db);
    }

    // ------------------------------------------------------------- access

    public function test_an_admin_sees_the_token_list(): void
    {
        $this->actingAs('admin');
        $this->tokens->create('Marketing site');

        $response = $this->get('/admin/tokens');

        self::assertSame(200, $response->status);
        self::assertStringContainsString('Marketing site', $response->body);
    }

    public function test_lifecycle_on_a_missing_id_reports_a_real_failure_not_a_false_success(): void
    {
        // ADMIN-14b: POST revoke on a nonexistent id used to flash "Token revoked."
        $this->actingAs('admin');

        $this->assertRedirects($this->post('/admin/tokens/999999/revoke'), '/admin/tokens?err=not-found');
        $body = $this->get('/admin/tokens', ['err' => 'not-found'])->body;
        self::assertStringContainsString('That token no longer exists.', $body);
        self::assertStringNotContainsString('Token revoked.', $body);
    }

    public function test_an_idempotent_re_revoke_of_a_real_token_stays_a_success(): void
    {
        // ADMIN-14b/A6: existence is checked, not affected-rows — so re-revoking a
        // real (already-revoked) token is still a success, never a false 'not-found'.
        $this->actingAs('admin');
        $this->tokens->create('Reusable');
        $id = $this->tokens->all()[0]->id;

        $this->assertRedirects($this->post("/admin/tokens/{$id}/revoke"), '/admin/tokens?msg=revoked');
        $this->assertRedirects($this->post("/admin/tokens/{$id}/revoke"), '/admin/tokens?msg=revoked');
    }

    public function test_a_non_admin_is_turned_away(): void
    {
        $this->actingAs('editor');

        $this->assertRedirects($this->get('/admin/tokens'), '/admin', 'editors cannot manage tokens');
    }

    public function test_an_anonymous_visitor_is_sent_to_login(): void
    {
        $this->assertRedirects($this->get('/admin/tokens'), '/admin/login');
    }

    // -------------------------------------------------------------- mint

    public function test_minting_shows_the_secret_once_and_does_not_redirect(): void
    {
        $this->actingAs('admin');

        $response = $this->mint('CI pipeline');

        self::assertSame(200, $response->status, 'the secret is rendered, not redirected');
        self::assertNull($response->header('Location'), 'a secret must never travel in a redirect');

        self::assertSame(1, preg_match('/nbt_[0-9a-f]{40}/', $response->body, $m), 'the plaintext is shown once');
        // The shown secret is the real, working token.
        $token = $this->tokens->findByPlaintext($m[0] ?? '');
        self::assertNotNull($token);
        self::assertSame('CI pipeline', $token->name);
    }

    public function test_the_secret_never_reappears_on_a_later_page_load(): void
    {
        $this->actingAs('admin');
        $plain = $this->extractSecret($this->mint('Once')->body);

        self::assertStringNotContainsString($plain, $this->get('/admin/tokens')->body, 'a reload must not re-reveal the secret');
    }

    public function test_an_expiry_preset_is_applied(): void
    {
        $this->actingAs('admin');
        $plain = $this->extractSecret($this->mint('Temp', '30d')->body);

        self::assertNotNull($this->tokens->findByPlaintext($plain)->expiresAt, 'a preset expiry is stored');
    }

    public function test_a_resubmitted_mint_creates_no_duplicate(): void
    {
        $this->actingAs('admin');
        $nonce = FormNonce::issue();

        $first = $this->post('/admin/tokens', ['name' => 'Once', 'expires' => 'never', 'scope_all' => '1', '_nonce' => $nonce]);
        self::assertCount(1, $this->tokens->all());
        self::assertSame(1, preg_match('/nbt_[0-9a-f]{40}/', $first->body));

        // A reload re-POSTs the same (now spent) nonce.
        $resubmit = $this->post('/admin/tokens', ['name' => 'Once', 'expires' => 'never', 'scope_all' => '1', '_nonce' => $nonce]);

        self::assertCount(1, $this->tokens->all(), 'a resubmit mints no duplicate');
        self::assertSame(0, preg_match('/nbt_[0-9a-f]{40}/', $resubmit->body), 'and shows no new secret');
    }

    public function test_all_collections_grants_read_all(): void
    {
        $this->actingAs('admin');

        $this->post('/admin/tokens', ['name' => 'Broad', 'scope_all' => '1', '_nonce' => FormNonce::issue()]);

        self::assertSame(['*:read'], $this->tokens->all()[0]->abilities);
    }

    public function test_specific_collections_grant_only_those(): void
    {
        $this->actingAs('admin');
        $this->makeCollection('posts');
        $this->makeCollection('pages');

        $this->post('/admin/tokens', ['name' => 'Narrow', 'scopes' => ['posts'], '_nonce' => FormNonce::issue()]);

        self::assertSame(['posts:read'], $this->tokens->all()[0]->abilities);
    }

    public function test_a_crafted_scope_for_an_unknown_collection_is_dropped(): void
    {
        $this->actingAs('admin');
        $this->makeCollection('posts');

        // "posts" is real, "ghost" is not: only the real handle becomes a scope.
        $this->post('/admin/tokens', ['name' => 'Craft', 'scopes' => ['posts', 'ghost'], '_nonce' => FormNonce::issue()]);

        self::assertSame(['posts:read'], $this->tokens->all()[0]->abilities);
    }

    public function test_a_token_manager_cannot_grant_read_all_it_does_not_hold(): void
    {
        // A custom role granting only `tokens:write` reaches this form (Slice 3
        // made management caps grantable to non-admins) — but it must not be able
        // to mint a read-all token it could never read with itself. Fails on the
        // pre-subset-only form.
        $this->actingWithCapabilities(['tokens:write']);

        $this->post('/admin/tokens', ['name' => 'Overreach', 'scope_all' => '1', '_nonce' => FormNonce::issue()]);

        self::assertSame([], $this->tokens->all(), 'a token-manager without *:read cannot mint a read-all token');
    }

    public function test_a_token_manager_cannot_grant_a_collection_it_cannot_read(): void
    {
        $this->actingWithCapabilities(['tokens:write']);
        $this->makeCollection('posts');

        $this->post('/admin/tokens', ['name' => 'Overreach', 'scopes' => ['posts'], '_nonce' => FormNonce::issue()]);

        self::assertSame([], $this->tokens->all(), 'granting posts:read requires holding it');
    }

    public function test_a_token_manager_may_grant_reads_it_holds(): void
    {
        $this->makeCollection('posts');
        $this->actingWithCapabilities(['tokens:write', 'posts:read']);

        $this->post('/admin/tokens', ['name' => 'Within reach', 'scopes' => ['posts'], '_nonce' => FormNonce::issue()]);

        self::assertSame(['posts:read'], $this->tokens->all()[0]->abilities, 'a scope the actor holds is grantable');
    }

    // -------------------------------------------------- role binding (Slice 4b-UI)

    public function test_mint_can_bind_a_role_the_actor_fully_holds(): void
    {
        $writer = (new \Nimbus\Auth\RoleRepository($this->db))->create('writer', ['posts:write'], false);
        $this->actingWithCapabilities(['tokens:write', 'posts:write']);

        $this->post('/admin/tokens', ['name' => 'bound', 'role' => (string) $writer, '_nonce' => FormNonce::issue()]);

        $all = $this->tokens->all();
        self::assertCount(1, $all, 'a role-only mint (no scopes) is allowed');
        self::assertSame($writer, $all[0]->roleId, 'the token is bound to the role');
    }

    public function test_mint_cannot_bind_a_role_beyond_the_actor(): void
    {
        $roles     = new \Nimbus\Auth\RoleRepository($this->db);
        $super     = $roles->create('superuser', ['admin'], false);
        $userAdmin = $roles->create('user-admin', ['users:write'], false);
        $this->actingWithCapabilities(['tokens:write', 'posts:write']);

        $this->post('/admin/tokens', ['name' => 'esc', 'role' => (string) $super, '_nonce' => FormNonce::issue()]);
        self::assertSame([], $this->tokens->all(), 'a crafted admin-role id cannot launder admin — server-side check, not the dropdown');

        $this->post('/admin/tokens', ['name' => 'esc2', 'role' => (string) $userAdmin, '_nonce' => FormNonce::issue()]);
        self::assertSame([], $this->tokens->all(), 'nor a role carrying an unheld management cap');
    }

    public function test_admin_can_bind_an_admin_role(): void
    {
        $super = (new \Nimbus\Auth\RoleRepository($this->db))->create('superuser', ['admin'], false);
        $this->actingAs('admin');

        $this->post('/admin/tokens', ['name' => 'deputy', 'role' => (string) $super, '_nonce' => FormNonce::issue()]);

        self::assertSame($super, $this->tokens->all()[0]->roleId, 'an admin may bind an admin-capable role');
    }

    public function test_a_crafted_or_unknown_role_id_is_rejected(): void
    {
        $this->actingAs('admin');

        foreach (['0', 'abc', '999999'] as $bad) {
            $this->post('/admin/tokens', ['name' => 'x', 'role' => $bad, 'scope_all' => '1', '_nonce' => FormNonce::issue()]);
        }

        self::assertSame([], $this->tokens->all(), 'a bad role id mints nothing, even with a valid scope');
    }

    public function test_a_held_role_does_not_excuse_an_unheld_scope(): void
    {
        // The union is checked on BOTH paths: a grantable role must not let an
        // unheld scope (*:read) ride along.
        $reader = (new \Nimbus\Auth\RoleRepository($this->db))->create('pages-reader', ['pages:read'], false);
        $this->makeCollection('pages');
        $this->actingWithCapabilities(['tokens:write', 'pages:read']);

        $this->post('/admin/tokens', ['name' => 'sneaky', 'role' => (string) $reader, 'scope_all' => '1', '_nonce' => FormNonce::issue()]);

        self::assertSame([], $this->tokens->all(), 'the scope check survives alongside role binding');
    }

    public function test_role_only_mint_requires_csrf(): void
    {
        $writer = (new \Nimbus\Auth\RoleRepository($this->db))->create('writer', ['posts:write'], false);
        $this->actingWithCapabilities(['tokens:write', 'posts:write']);

        $this->postWithoutCsrf('/admin/tokens', ['name' => 'nocsrf', 'role' => (string) $writer]);

        self::assertSame([], $this->tokens->all(), 'a role-only mint still needs a CSRF token');
    }

    public function test_the_role_dropdown_offers_only_grantable_roles(): void
    {
        $roles = new \Nimbus\Auth\RoleRepository($this->db);
        $roles->create('writer', ['posts:write'], false);
        $roles->create('superuser', ['admin'], false);
        $this->actingWithCapabilities(['tokens:write', 'posts:write']);

        $body = $this->get('/admin/tokens')->body;

        self::assertStringContainsString('>writer</option>', $body, 'a grantable role is offered');
        self::assertStringNotContainsString('>superuser</option>', $body, 'an unbindable role is not offered (defense-in-depth over the server check)');
    }

    public function test_choosing_no_access_is_rejected(): void
    {
        $this->actingAs('admin');

        // Neither "all" nor any specific collection — deny by default.
        $this->post('/admin/tokens', ['name' => 'Empty', '_nonce' => FormNonce::issue()]);

        self::assertSame([], $this->tokens->all(), 'a token with no access is not minted');
    }

    public function test_minting_requires_csrf(): void
    {
        $this->actingAs('admin');

        $this->postWithoutCsrf('/admin/tokens', ['name' => 'Forged']);

        self::assertSame([], $this->tokens->all(), 'no token is minted without a CSRF token');
    }

    public function test_an_empty_name_is_rejected(): void
    {
        $this->actingAs('admin');

        $this->post('/admin/tokens', ['name' => '   ']);

        self::assertSame([], $this->tokens->all());
    }

    // --------------------------------------------------------- lifecycle

    public function test_pause_resume_and_revoke_change_what_the_api_will_accept(): void
    {
        $this->actingAs('admin');
        $plain = $this->tokens->create('App');
        $id    = $this->tokens->findByPlaintext($plain)->id;

        $this->post("/admin/tokens/{$id}/pause");
        self::assertNull($this->tokens->findByPlaintext($plain), 'a paused token stops authenticating');

        $this->post("/admin/tokens/{$id}/resume");
        self::assertNotNull($this->tokens->findByPlaintext($plain), 'resuming restores it');

        $this->post("/admin/tokens/{$id}/revoke");
        self::assertNull($this->tokens->findByPlaintext($plain), 'a revoked token is gone for good');
    }

    public function test_lifecycle_actions_require_csrf(): void
    {
        $this->actingAs('admin');
        $plain = $this->tokens->create('App');
        $id    = $this->tokens->findByPlaintext($plain)->id;

        $this->postWithoutCsrf("/admin/tokens/{$id}/revoke");

        self::assertNotNull($this->tokens->findByPlaintext($plain), 'a forged revoke does nothing');
    }

    public function test_a_non_admin_cannot_revoke(): void
    {
        $plain = $this->tokens->create('App');
        $id    = $this->tokens->findByPlaintext($plain)->id;
        $this->actingAs('editor');

        $this->assertRedirects($this->post("/admin/tokens/{$id}/revoke"), '/admin');
        self::assertNotNull($this->tokens->findByPlaintext($plain), 'the token survives a non-admin revoke attempt');
    }

    /** Mint through the UI with a fresh nonce and "all collections" access. */
    private function mint(string $name, string $expires = 'never'): \Nimbus\Http\Response
    {
        return $this->post('/admin/tokens', [
            'name' => $name, 'expires' => $expires, 'scope_all' => '1', '_nonce' => FormNonce::issue(),
        ]);
    }

    private function extractSecret(string $body): string
    {
        self::assertSame(1, preg_match('/nbt_[0-9a-f]{40}/', $body, $m), 'expected a minted secret in the response');
        return $m[0] ?? '';
    }
}

<?php

declare(strict_types=1);

namespace Nimbus\Tests\Http;

use Nimbus\Auth\Auth;
use Nimbus\Auth\PasswordResetRepository;
use Nimbus\Tests\Support\SpyMailer;

/**
 * User invitation — admin invites by email; the invitee sets their own password
 * via a one-time `invite` token (the reset primitive, purpose-scoped). Locks the
 * account-provisioning controls: purpose isolation from reset, no pre-accept
 * login, subset-only role guard on invite, CSRF, single-use, and delivery-failure
 * surfaced to the admin.
 */
final class UserInvitationTest extends HttpTestCase
{
    private SpyMailer $spy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->spy    = new SpyMailer();
        $this->mailer = $this->spy;
    }

    /** @return array<string,mixed>|null */
    private function userByEmail(string $email): ?array
    {
        return $this->db->selectOne('SELECT * FROM nb_users WHERE email = :e', ['e' => $email]);
    }

    // --------------------------------------------------------------- invite

    public function test_blank_password_sends_an_invite_and_creates_a_passwordless_user(): void
    {
        $this->actingAs('admin');

        $resp = $this->post('/admin/users', ['email' => 'newbie@test.local', 'name' => 'Newbie', 'password' => '']);

        $this->assertRedirectsTo($resp, '/admin/users?msg=invited');
        self::assertCount(1, $this->spy->sent);
        self::assertSame('newbie@test.local', $this->spy->sent[0]['to']);
        self::assertNotNull($this->spy->lastToken(), 'the invite email carries an accept link');
        self::assertNotNull($this->userByEmail('newbie@test.local'), 'the user is created immediately');
    }

    public function test_a_typed_password_still_creates_directly_without_an_email(): void
    {
        $this->actingAs('admin');

        $resp = $this->post('/admin/users', ['email' => 'direct@test.local', 'name' => 'Direct', 'password' => 'a-strong-passphrase']);

        $this->assertRedirectsTo($resp, '/admin/users?msg=created');
        self::assertSame([], $this->spy->sent, 'direct create sends no invite');
        self::assertTrue((new Auth($this->db))->attempt('direct@test.local', 'a-strong-passphrase'));
    }

    /** #2 — an invited-but-unaccepted user cannot log in with any password. */
    public function test_an_invited_user_cannot_log_in_until_they_accept(): void
    {
        $this->actingAs('admin');
        $this->post('/admin/users', ['email' => 'pending@test.local', 'password' => '']);

        $auth = new Auth($this->db);
        self::assertFalse($auth->attempt('pending@test.local', ''));
        self::assertFalse($auth->attempt('pending@test.local', 'password'));
        self::assertFalse($auth->attempt('pending@test.local', 'guess-guess-guess'));
    }

    public function test_accepting_an_invite_sets_the_password_and_activates_login(): void
    {
        $this->actingAs('admin');
        $this->post('/admin/users', ['email' => 'newbie@test.local', 'password' => '']);
        $token = (string) $this->spy->lastToken();

        // The accept page renders for a valid invite token.
        self::assertStringContainsString('Activate account', $this->get('/admin/accept', ['token' => $token])->body);

        $this->resetSession();
        $resp = $this->post('/admin/accept', ['token' => $token, 'password' => 'a-brand-new-passphrase']);
        $this->assertRedirectsTo($resp, '/admin/login?welcome=1');

        self::assertTrue((new Auth($this->db))->attempt('newbie@test.local', 'a-brand-new-passphrase'));
    }

    // --------------------------------------------------------- purpose isolation

    public function test_an_invite_token_cannot_be_spent_at_the_reset_endpoint(): void
    {
        $this->actingAs('admin');
        $this->post('/admin/users', ['email' => 'newbie@test.local', 'password' => '']);
        $token = (string) $this->spy->lastToken();

        // Same token, wrong purpose endpoint → rejected; no password set.
        $this->resetSession();
        $resp = $this->post('/admin/reset', ['token' => $token, 'password' => 'a-brand-new-passphrase']);
        self::assertStringContainsString('invalid', strtolower($resp->body));
        self::assertFalse((new Auth($this->db))->attempt('newbie@test.local', 'a-brand-new-passphrase'));
    }

    public function test_a_reset_request_does_not_invalidate_a_pending_invite(): void
    {
        $this->actingAs('admin');
        $this->post('/admin/users', ['email' => 'newbie@test.local', 'password' => '']);
        $inviteToken = (string) $this->spy->lastToken();

        // The invited user requests a password reset — different purpose.
        $this->resetSession();
        $this->post('/admin/forgot', ['email' => 'newbie@test.local']);

        // The invite token is still valid (purpose-scoped invalidation).
        $repo = new PasswordResetRepository($this->db);
        self::assertNotNull(
            $repo->userIdForValidToken($inviteToken, PasswordResetRepository::PURPOSE_INVITE),
            'issuing a reset must not kill a pending invite',
        );
    }

    public function test_an_invite_is_single_use(): void
    {
        $this->actingAs('admin');
        $this->post('/admin/users', ['email' => 'newbie@test.local', 'password' => '']);
        $token = (string) $this->spy->lastToken();

        $this->resetSession();
        $this->post('/admin/accept', ['token' => $token, 'password' => 'a-brand-new-passphrase']);
        $second = $this->post('/admin/accept', ['token' => $token, 'password' => 'another-strong-passphrase']);
        self::assertStringContainsString('invalid', strtolower($second->body));
    }

    // ------------------------------------------------------------- guards

    public function test_invite_respects_the_subset_only_role_guard(): void
    {
        // A non-admin who can manage users but only holds a scoped role must not
        // invite someone into the admin role.
        $adminRole = (new \Nimbus\Auth\RoleRepository($this->db))->create('admin-role', ['admin'], false);
        $this->actingWithCapabilities(['users:write']);

        $resp = $this->post('/admin/users', ['email' => 'escalate@test.local', 'password' => '', 'roles' => [$adminRole]]);

        $this->assertRedirectsTo($resp, '/admin/users');
        self::assertStringContainsString('err=', (string) $resp->header('Location'));
        self::assertSame([], $this->spy->sent, 'no invite when the role grant is refused');
        self::assertNull($this->userByEmail('escalate@test.local'), 'no user created on a refused escalation');
    }

    public function test_accept_requires_csrf(): void
    {
        $this->actingAs('admin');
        $this->post('/admin/users', ['email' => 'newbie@test.local', 'password' => '']);
        $token = (string) $this->spy->lastToken();

        $this->resetSession();
        $this->postWithoutCsrf('/admin/accept', ['token' => $token, 'password' => 'a-brand-new-passphrase']);
        self::assertFalse((new Auth($this->db))->attempt('newbie@test.local', 'a-brand-new-passphrase'));
    }

    public function test_a_delivery_failure_is_reported_to_the_admin(): void
    {
        $this->spy->fail = true;
        $this->actingAs('admin');

        $resp = $this->post('/admin/users', ['email' => 'newbie@test.local', 'password' => '']);

        // Unlike the public reset flow, the admin IS told the send failed.
        $this->assertRedirectsTo($resp, '/admin/users?msg=invited-nomail');
        self::assertNotNull($this->userByEmail('newbie@test.local'), 'the user still exists to re-invite');
    }

    public function test_the_invite_email_uses_the_configured_site_title(): void
    {
        (new \Nimbus\Settings\SettingsRepository($this->db))->set('site.title', 'Danmat Studio');
        $this->actingAs('admin');

        $this->post('/admin/users', ['email' => 'newbie@test.local', 'password' => '']);

        self::assertStringContainsString('Danmat Studio', $this->spy->sent[0]['subject']);
        self::assertStringContainsString('Danmat Studio', $this->spy->sent[0]['body']);
    }

    public function test_the_accept_page_forbids_the_referer(): void
    {
        $this->actingAs('admin');
        $this->post('/admin/users', ['email' => 'newbie@test.local', 'password' => '']);
        $token = (string) $this->spy->lastToken();

        self::assertSame('no-referrer', $this->get('/admin/accept', ['token' => $token])->header('Referrer-Policy'));
    }
}

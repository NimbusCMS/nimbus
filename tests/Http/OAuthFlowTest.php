<?php

declare(strict_types=1);

namespace Nimbus\Tests\Http;

use Nimbus\Auth\OAuth\OAuthIdentity;
use Nimbus\Auth\OAuth\OAuthIdentityRepository;
use Nimbus\Auth\OAuth\OAuthProviders;
use Nimbus\Tests\Support\FakeOAuthProvider;

/**
 * Drives the real SSO kernel path (ADR 0012) with a network-free provider. Each
 * test runs a genuine `start` (mints a session-bound state + PKCE) and then the
 * `callback`, so state single-use, PKCE, provider-binding, the open-redirect
 * guard, session rotation, and the link/sign-in dispatch are all exercised
 * end-to-end — no mocking of the controller or the session.
 */
final class OAuthFlowTest extends HttpTestCase
{
    private const SUB = 'provider-subject-123';

    private function identity(string $sub = self::SUB, string $email = 'user@example.com', bool $verified = true): OAuthIdentity
    {
        return new OAuthIdentity($sub, $email, $verified, 'Example User');
    }

    private function useProvider(OAuthIdentity $identity, string $key = 'google'): FakeOAuthProvider
    {
        $fake = new FakeOAuthProvider($key, $identity, 'Google');
        $this->oauthProviders = new OAuthProviders([$key => $fake]);
        return $fake;
    }

    private function repo(): OAuthIdentityRepository
    {
        return new OAuthIdentityRepository($this->db);
    }

    /** The state minted by the most recent `start`, read from the real session. */
    private function currentState(): string
    {
        return (string) ($_SESSION['nimbus_oauth_flow']['state'] ?? '');
    }

    // ---------------------------------------------------------- off by default

    public function test_sso_is_off_by_default(): void
    {
        // No providers injected and none configured → no buttons, start rejected.
        $login = $this->get('/admin/login');
        $this->assertOkHtml($login);
        self::assertStringNotContainsString('Continue with', $login->body);

        $this->assertRedirects($this->get('/admin/oauth/google/start'), '/admin/login?oauth_error=config');
    }

    public function test_login_button_appears_only_when_configured(): void
    {
        putenv('OAUTH_GOOGLE_CLIENT_ID=id');
        putenv('OAUTH_GOOGLE_CLIENT_SECRET=sekret-xyz-9000');
        try {
            $login = $this->get('/admin/login');
            self::assertStringContainsString('Continue with Google', $login->body);
            // The secret is never rendered front-channel.
            self::assertStringNotContainsString('sekret-xyz-9000', $login->body);
        } finally {
            putenv('OAUTH_GOOGLE_CLIENT_ID');
            putenv('OAUTH_GOOGLE_CLIENT_SECRET');
        }
    }

    // ------------------------------------------------------------- sign-in

    public function test_linked_identity_signs_in(): void
    {
        $uid = $this->createUser('admin');
        $this->repo()->link($uid, 'google', self::SUB, 'user@example.com');
        $this->useProvider($this->identity());

        $before = $this->sessionId();
        $start  = $this->get('/admin/oauth/google/start', ['intent' => 'login']);
        $this->assertRedirectsTo($start, 'https://provider.test/authorize');

        $cb = $this->get('/admin/oauth/google/callback', ['code' => 'abc', 'state' => $this->currentState()]);

        $this->assertRedirects($cb, '/admin');
        self::assertSame($uid, $_SESSION['nimbus_uid'] ?? null, 'the linked user is now signed in');
        self::assertNotSame($before, $this->sessionId(), 'the session id rotates on SSO sign-in');
    }

    public function test_unknown_identity_is_rejected(): void
    {
        // A valid provider identity that is not linked to any user.
        $this->useProvider($this->identity('nobody-999'));

        $this->get('/admin/oauth/google/start', ['intent' => 'login']);
        $cb = $this->get('/admin/oauth/google/callback', ['code' => 'abc', 'state' => $this->currentState()]);

        $this->assertRedirects($cb, '/admin/login?oauth_error=unknown');
        self::assertArrayNotHasKey('nimbus_uid', $_SESSION, 'no session is established for an unknown identity');
    }

    // -------------------------------------------------------- state / PKCE

    public function test_state_mismatch_is_rejected(): void
    {
        $uid = $this->createUser('admin');
        $this->repo()->link($uid, 'google', self::SUB, 'u@e.com');
        $this->useProvider($this->identity());

        $this->get('/admin/oauth/google/start', ['intent' => 'login']);
        $cb = $this->get('/admin/oauth/google/callback', ['code' => 'abc', 'state' => 'not-the-real-state']);

        $this->assertRedirects($cb, '/admin/login?oauth_error=provider');
        self::assertArrayNotHasKey('nimbus_uid', $_SESSION);
    }

    public function test_state_is_single_use(): void
    {
        $uid = $this->createUser('admin');
        $this->repo()->link($uid, 'google', self::SUB, 'u@e.com');
        $this->useProvider($this->identity());

        $this->get('/admin/oauth/google/start', ['intent' => 'login']);
        $state = $this->currentState();

        $first = $this->get('/admin/oauth/google/callback', ['code' => 'abc', 'state' => $state]);
        $this->assertRedirects($first, '/admin'); // consumed here

        // Replaying the very same state must not sign anyone in again.
        $replay = $this->get('/admin/oauth/google/callback', ['code' => 'abc', 'state' => $state]);
        self::assertStringContainsString('oauth_error=provider', (string) $replay->header('Location'));
    }

    public function test_pkce_and_state_are_sent_on_start(): void
    {
        $fake = $this->useProvider($this->identity());
        $this->get('/admin/oauth/google/start', ['intent' => 'login']);

        self::assertNotNull($fake->lastState);
        self::assertNotNull($fake->lastChallenge);
        // The challenge is the base64url S256 of the (session-stored) verifier.
        $verifier = (string) ($_SESSION['nimbus_oauth_flow']['verifier'] ?? '');
        self::assertNotSame('', $verifier);
        $expected = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        self::assertSame($expected, $fake->lastChallenge, 'PKCE challenge = S256(verifier)');
    }

    // --------------------------------------------------------- open redirect

    public function test_open_redirect_next_is_blocked(): void
    {
        $uid = $this->createUser('admin');
        $this->repo()->link($uid, 'google', self::SUB, 'u@e.com');
        $this->useProvider($this->identity());

        $this->get('/admin/oauth/google/start', ['intent' => 'login', 'next' => '//evil.example']);
        $cb = $this->get('/admin/oauth/google/callback', ['code' => 'abc', 'state' => $this->currentState()]);

        $this->assertRedirects($cb, '/admin', 'a scheme-relative next falls back to the dashboard');
    }

    public function test_internal_next_is_honoured(): void
    {
        $uid = $this->createUser('admin');
        $this->repo()->link($uid, 'google', self::SUB, 'u@e.com');
        $this->useProvider($this->identity());

        $this->get('/admin/oauth/google/start', ['intent' => 'login', 'next' => '/admin/media']);
        $cb = $this->get('/admin/oauth/google/callback', ['code' => 'abc', 'state' => $this->currentState()]);

        $this->assertRedirects($cb, '/admin/media');
    }

    // ------------------------------------------------------ provider binding

    public function test_provider_confusion_is_rejected(): void
    {
        $uid = $this->createUser('admin');
        $this->repo()->link($uid, 'google', self::SUB, 'u@e.com');
        $this->useProvider($this->identity()); // google only

        $this->get('/admin/oauth/google/start', ['intent' => 'login']);
        // Complete against a DIFFERENT provider's callback with google's state.
        $cb = $this->get('/admin/oauth/github/callback', ['code' => 'abc', 'state' => $this->currentState()]);

        self::assertStringContainsString('oauth_error=provider', (string) $cb->header('Location'));
        self::assertArrayNotHasKey('nimbus_uid', $_SESSION);
    }

    // ------------------------------------------------------------- linking

    public function test_explicit_link_from_settings(): void
    {
        $uid = $this->actingAs('admin');
        $this->useProvider($this->identity('link-sub-1', 'me@example.com'));

        $this->get('/admin/oauth/google/start', ['intent' => 'link']);
        $cb = $this->get('/admin/oauth/google/callback', ['code' => 'abc', 'state' => $this->currentState()]);

        $this->assertRedirectsTo($cb, '/admin/settings?oauth=linked');
        self::assertSame($uid, $this->repo()->userIdFor('google', 'link-sub-1'));
    }

    public function test_link_is_bound_to_the_initiating_user(): void
    {
        $userA = $this->actingAs('admin', 'a@test.local');
        $this->useProvider($this->identity('shared-sub', 'a@example.com'));

        // A starts a link flow (uid=A recorded in the session).
        $this->get('/admin/oauth/google/start', ['intent' => 'link']);
        $state = $this->currentState();

        // The session user changes to B before the callback returns. A fresh Auth
        // is used so the callback resolves the *current* session user (the kernel
        // builds a new Auth per request; the harness otherwise reuses one).
        $userB = $this->createUser('editor', 'b@test.local');
        $_SESSION['nimbus_uid'] = $userB;
        $this->auth = new \Nimbus\Auth\Auth($this->db);

        $cb = $this->get('/admin/oauth/google/callback', ['code' => 'abc', 'state' => $state]);

        self::assertStringContainsString('oauth_error=', (string) $cb->header('Location'));
        self::assertNull($this->repo()->userIdFor('google', 'shared-sub'), 'the identity is linked to nobody');
    }

    public function test_identity_already_linked_elsewhere_is_not_stolen(): void
    {
        $owner = $this->createUser('admin', 'owner@test.local');
        $this->repo()->link($owner, 'google', 'claimed-sub', 'owner@example.com');

        $other = $this->actingAs('editor', 'other@test.local');
        $this->useProvider($this->identity('claimed-sub', 'other@example.com'));

        $this->get('/admin/oauth/google/start', ['intent' => 'link']);
        $cb = $this->get('/admin/oauth/google/callback', ['code' => 'abc', 'state' => $this->currentState()]);

        $this->assertRedirectsTo($cb, '/admin/settings?oauth_error=already');
        self::assertSame($owner, $this->repo()->userIdFor('google', 'claimed-sub'), 'the identity still belongs to its owner');
    }

    // ------------------------------------------------------------ disconnect

    public function test_disconnect_removes_the_link(): void
    {
        $uid = $this->actingAs('admin');
        $this->repo()->link($uid, 'google', self::SUB, 'u@e.com');

        $cb = $this->post('/admin/oauth/google/disconnect');
        $this->assertRedirectsTo($cb, '/admin/settings?oauth=disconnected');
        self::assertNull($this->repo()->userIdFor('google', self::SUB));
    }

    public function test_disconnect_requires_csrf(): void
    {
        $uid = $this->actingAs('admin');
        $this->repo()->link($uid, 'google', self::SUB, 'u@e.com');

        $this->postWithoutCsrf('/admin/oauth/google/disconnect');
        self::assertSame($uid, $this->repo()->userIdFor('google', self::SUB), 'a forged disconnect changes nothing');
    }

    public function test_provider_exchange_failure_is_handled(): void
    {
        $uid  = $this->createUser('admin');
        $this->repo()->link($uid, 'google', self::SUB, 'u@e.com');
        $fake = $this->useProvider($this->identity());
        $fake->failExchange = true;

        $this->get('/admin/oauth/google/start', ['intent' => 'login']);
        $cb = $this->get('/admin/oauth/google/callback', ['code' => 'abc', 'state' => $this->currentState()]);

        $this->assertRedirects($cb, '/admin/login?oauth_error=provider');
        self::assertArrayNotHasKey('nimbus_uid', $_SESSION);
    }
}

<?php

declare(strict_types=1);

namespace Nimbus\Tests\Http;

use Nimbus\Auth\Auth;
use Nimbus\Auth\Password;

/**
 * Self-service **change password** for the signed-in user (`POST
 * /admin/settings/password`). The security invariants (nimbus-security-review):
 * CSRF-guarded; the current password is re-authenticated BEFORE any change (a
 * CSRF token alone can't stop a session-rider — it holds the token); the
 * current-password check is throttled per-account so it can't be a guessing
 * oracle; the new password is floor-validated; you can only ever change your
 * OWN password (uid from the session, no over-posting); a submitted password is
 * never echoed back into the form; and a change invalidates every OTHER session
 * (A4) while keeping the changing one.
 */
final class AccountPasswordTest extends HttpTestCase
{
    private const CURRENT = 'correct-horse';   // createUser()'s default
    private const NEW     = 'a-brand-new-passphrase';

    private function storedHash(int $id): string
    {
        $row = $this->db->selectOne('SELECT password FROM nb_users WHERE id = :id', ['id' => $id]);
        self::assertNotNull($row);
        return (string) $row['password'];
    }

    /** @return array<string,mixed>|null */
    private function throttleRow(int $id): ?array
    {
        return $this->db->selectOne('SELECT * FROM nb_login_throttle WHERE id = :k', ['k' => 'changepw:' . $id]);
    }

    public function test_csrf_is_required(): void
    {
        $id = $this->actingAs('admin');
        $before = $this->storedHash($id);
        $this->postWithoutCsrf('/admin/settings/password', [
            'current_password' => self::CURRENT, 'new_password' => self::NEW, 'confirm_password' => self::NEW,
        ]);
        self::assertSame($before, $this->storedHash($id), 'password must not change without a CSRF token');
    }

    public function test_wrong_current_password_is_rejected_and_throttled(): void
    {
        $id = $this->actingAs('admin');
        $before = $this->storedHash($id);
        $resp = $this->post('/admin/settings/password', [
            'current_password' => 'not-my-password', 'new_password' => self::NEW, 'confirm_password' => self::NEW,
        ]);
        self::assertStringContainsString('current password is incorrect', $resp->body);
        self::assertSame($before, $this->storedHash($id));
        self::assertNotNull($this->throttleRow($id), 'a wrong current password records a throttle failure');
    }

    public function test_throttle_blocks_even_a_correct_current_password_once_locked(): void
    {
        $id = $this->actingAs('admin');
        for ($i = 0; $i < 5; $i++) {
            $this->post('/admin/settings/password', [
                'current_password' => 'wrong', 'new_password' => self::NEW, 'confirm_password' => self::NEW,
            ]);
        }
        $before = $this->storedHash($id);
        // Now the CORRECT current password must also be blocked — proving the
        // throttle check runs BEFORE the verify (oracle closed).
        $resp = $this->post('/admin/settings/password', [
            'current_password' => self::CURRENT, 'new_password' => self::NEW, 'confirm_password' => self::NEW,
        ]);
        self::assertStringContainsString('Too many attempts', $resp->body);
        self::assertSame($before, $this->storedHash($id), 'a locked account cannot change its password even with the right current one');
    }

    public function test_weak_new_password_is_rejected(): void
    {
        $id = $this->actingAs('admin');
        $before = $this->storedHash($id);
        $resp = $this->post('/admin/settings/password', [
            'current_password' => self::CURRENT, 'new_password' => 'shortpw', 'confirm_password' => 'shortpw',
        ]);
        self::assertStringContainsString('at least ' . Password::MIN_LENGTH, $resp->body);
        self::assertSame($before, $this->storedHash($id));
    }

    public function test_mismatch_and_noop_are_rejected(): void
    {
        $id = $this->actingAs('admin');
        $before = $this->storedHash($id);

        $mismatch = $this->post('/admin/settings/password', [
            'current_password' => self::CURRENT, 'new_password' => self::NEW, 'confirm_password' => 'different-passphrase',
        ]);
        self::assertStringContainsString('don’t match', $mismatch->body);

        $noop = $this->post('/admin/settings/password', [
            'current_password' => self::CURRENT, 'new_password' => self::CURRENT, 'confirm_password' => self::CURRENT,
        ]);
        self::assertStringContainsString('different from your current', $noop->body);

        self::assertSame($before, $this->storedHash($id));
    }

    public function test_success_changes_the_password_and_clears_the_throttle(): void
    {
        $id = $this->actingAs('admin');
        // one prior failure to prove success clears the throttle
        $this->post('/admin/settings/password', ['current_password' => 'wrong', 'new_password' => self::NEW, 'confirm_password' => self::NEW]);

        $resp = $this->post('/admin/settings/password', [
            'current_password' => self::CURRENT, 'new_password' => self::NEW, 'confirm_password' => self::NEW,
        ]);
        $this->assertRedirectsTo($resp, '/admin/settings?flash=password');

        $hash = $this->storedHash($id);
        self::assertFalse(Password::verify(self::CURRENT, $hash), 'old password no longer works');
        self::assertTrue(Password::verify(self::NEW, $hash), 'new password works');
        self::assertFalse(Password::needsRehash($hash), 'stored at the current algorithm');
        self::assertNull($this->throttleRow($id), 'a successful change clears the throttle');
    }

    public function test_you_cannot_over_post_another_users_password(): void
    {
        $victim = $this->createUser('editor', 'victim@test.local');
        $victimHash = $this->storedHash($victim);
        $me = $this->actingAs('admin');

        $this->post('/admin/settings/password', [
            'user_id' => $victim, 'id' => $victim, 'role' => 'admin', 'email' => 'x@x.test',
            'current_password' => self::CURRENT, 'new_password' => self::NEW, 'confirm_password' => self::NEW,
        ]);

        self::assertSame($victimHash, $this->storedHash($victim), "another user's password is untouched");
        self::assertTrue(Password::verify(self::NEW, $this->storedHash($me)), 'only the acting user changed');
    }

    public function test_a_submitted_password_is_never_echoed_back(): void
    {
        $this->actingAs('admin');
        $secret = 'my-typo-secret-value';
        $resp = $this->post('/admin/settings/password', [
            'current_password' => 'wrong', 'new_password' => $secret, 'confirm_password' => $secret,
        ]);
        self::assertStringNotContainsString($secret, $resp->body, 'password inputs must never be repopulated on error');
    }

    public function test_changing_session_survives_and_stale_sessions_are_invalidated(): void
    {
        // A: the changing session survives its own change.
        $id = $this->actingAs('admin');
        $this->get('/admin/settings'); // backfills the session stamp
        $this->post('/admin/settings/password', [
            'current_password' => self::CURRENT, 'new_password' => self::NEW, 'confirm_password' => self::NEW,
        ]);
        self::assertNotNull((new Auth($this->db))->user(), 'the session that changed the password stays logged in');

        // B: a different session (stamped with the OLD hash) is logged out after
        // an out-of-band change. Re-establish a real stamped session, then change
        // the password behind its back.
        $this->resetSession();
        self::assertTrue($this->auth->attempt('admin@test.local', self::NEW));
        (new \Nimbus\Auth\UserRepository($this->db))->setPassword($id, Password::hash('yet-another-passphrase'));
        self::assertNull((new Auth($this->db))->user(), 'a session holding a stale password stamp is logged out');
    }
}

<?php

declare(strict_types=1);

namespace Nimbus\Tests\Http;

use Nimbus\Auth\PasswordResetRepository;
use Nimbus\Tests\Support\SpyMailer;

/**
 * Self-service password reset — the highest-stakes flow (account takeover), so
 * these lock every control the security review required: no enumeration, hashed
 * single-use tokens, expiry, strength gate, CSRF, throttle, Referrer-Policy, and
 * that the password actually rotates.
 */
final class PasswordResetTest extends HttpTestCase
{
    private SpyMailer $spy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->spy    = new SpyMailer();
        $this->mailer = $this->spy; // captured by throughKernel
    }

    private function requestReset(string $email): void
    {
        $this->post('/admin/forgot', ['email' => $email]);
    }

    /** @return list<array<string,mixed>> the nb_password_resets rows */
    private function resetRows(): array
    {
        return $this->db->select('SELECT * FROM nb_password_resets');
    }

    // ------------------------------------------------------------- request

    public function test_a_real_email_gets_a_reset_link(): void
    {
        $this->createUser('admin', 'ada@test.local');

        $resp = $this->post('/admin/forgot', ['email' => 'ada@test.local']);

        self::assertStringContainsString('a reset link is on its way', $resp->body);
        self::assertCount(1, $this->spy->sent);
        self::assertSame('ada@test.local', $this->spy->sent[0]['to']);
        self::assertNotNull($this->spy->lastToken(), 'the email carries a reset link');
    }

    public function test_an_unknown_email_looks_identical_and_sends_nothing(): void
    {
        $known = $this->post('/admin/forgot', ['email' => 'nobody@test.local']);

        self::assertStringContainsString('a reset link is on its way', $known->body, 'no enumeration in the body');
        self::assertSame([], $this->spy->sent, 'no mail for an unknown account');
        self::assertSame([], $this->resetRows(), 'no token minted for an unknown account');
    }

    public function test_the_token_is_stored_hashed_not_plaintext(): void
    {
        $this->createUser('admin', 'ada@test.local');
        $this->requestReset('ada@test.local');
        $token = (string) $this->spy->lastToken();

        $rows = $this->resetRows();
        self::assertCount(1, $rows);
        self::assertSame(PasswordResetRepository::hash($token), $rows[0]['token_hash']);
        self::assertNotSame($token, $rows[0]['token_hash'], 'the plaintext token is never stored');
    }

    public function test_requesting_again_invalidates_the_prior_link(): void
    {
        $this->createUser('admin', 'ada@test.local');
        $this->requestReset('ada@test.local');
        $first = (string) $this->spy->lastToken();
        $this->requestReset('ada@test.local');
        $second = (string) $this->spy->lastToken();

        self::assertNotSame($first, $second);
        self::assertStringContainsString('invalid', strtolower($this->get('/admin/reset', ['token' => $first])->body), 'the first link is dead');
        self::assertStringContainsString('Set password', $this->get('/admin/reset', ['token' => $second])->body, 'the newest link works');
    }

    // ------------------------------------------------------------- reset

    public function test_a_valid_token_resets_the_password(): void
    {
        $id = $this->createUser('admin', 'ada@test.local', 'the-old-password');
        $this->requestReset('ada@test.local');
        $token = (string) $this->spy->lastToken();

        $resp = $this->post('/admin/reset', ['token' => $token, 'password' => 'a-brand-new-passphrase']);
        $this->assertRedirectsTo($resp, '/admin/login?reset=1');

        // The new password works; the old one no longer does.
        $this->resetSession();
        self::assertTrue((new \Nimbus\Auth\Auth($this->db))->attempt('ada@test.local', 'a-brand-new-passphrase'));
        $this->resetSession();
        self::assertFalse((new \Nimbus\Auth\Auth($this->db))->attempt('ada@test.local', 'the-old-password'));
        self::assertSame($id, $id);
    }

    public function test_a_token_is_single_use(): void
    {
        $this->createUser('admin', 'ada@test.local');
        $this->requestReset('ada@test.local');
        $token = (string) $this->spy->lastToken();

        $this->post('/admin/reset', ['token' => $token, 'password' => 'a-brand-new-passphrase']);
        // Second use of the same token is rejected.
        $second = $this->post('/admin/reset', ['token' => $token, 'password' => 'another-strong-passphrase']);
        self::assertStringContainsString('invalid', strtolower($second->body));
        self::assertFalse((new \Nimbus\Auth\Auth($this->db))->attempt('ada@test.local', 'another-strong-passphrase'));
    }

    public function test_an_expired_token_is_rejected(): void
    {
        $this->createUser('admin', 'ada@test.local');
        $this->requestReset('ada@test.local');
        $token = (string) $this->spy->lastToken();
        $this->db->execute('UPDATE nb_password_resets SET expires_at = :e', ['e' => date('Y-m-d H:i:s', time() - 60)]);

        $resp = $this->post('/admin/reset', ['token' => $token, 'password' => 'a-brand-new-passphrase']);
        self::assertStringContainsString('invalid', strtolower($resp->body));
        self::assertFalse((new \Nimbus\Auth\Auth($this->db))->attempt('ada@test.local', 'a-brand-new-passphrase'));
    }

    public function test_a_weak_password_is_rejected_and_the_link_survives(): void
    {
        $this->createUser('admin', 'ada@test.local');
        $this->requestReset('ada@test.local');
        $token = (string) $this->spy->lastToken();

        $weak = $this->post('/admin/reset', ['token' => $token, 'password' => 'short']);
        self::assertStringContainsString('stronger password', $weak->body);

        // The token was NOT consumed — a strong retry with the same link works.
        $ok = $this->post('/admin/reset', ['token' => $token, 'password' => 'a-brand-new-passphrase']);
        $this->assertRedirectsTo($ok, '/admin/login?reset=1');
    }

    public function test_the_reset_page_forbids_the_referer(): void
    {
        $this->createUser('admin', 'ada@test.local');
        $this->requestReset('ada@test.local');
        $token = (string) $this->spy->lastToken();

        self::assertSame('no-referrer', $this->get('/admin/reset', ['token' => $token])->header('Referrer-Policy'));
    }

    // ------------------------------------------------------------- guards

    public function test_forgot_requires_csrf(): void
    {
        $this->createUser('admin', 'ada@test.local');

        $this->postWithoutCsrf('/admin/forgot', ['email' => 'ada@test.local']);

        self::assertSame([], $this->spy->sent, 'no mail without a CSRF token');
    }

    public function test_reset_requires_csrf(): void
    {
        $this->createUser('admin', 'ada@test.local', 'the-old-password');
        $this->requestReset('ada@test.local');
        $token = (string) $this->spy->lastToken();

        $this->postWithoutCsrf('/admin/reset', ['token' => $token, 'password' => 'a-brand-new-passphrase']);

        self::assertFalse((new \Nimbus\Auth\Auth($this->db))->attempt('ada@test.local', 'a-brand-new-passphrase'), 'no change without CSRF');
    }

    public function test_repeated_requests_are_throttled(): void
    {
        $this->createUser('admin', 'ada@test.local');

        for ($i = 0; $i < 10; $i++) {
            $this->requestReset('ada@test.local');
        }

        self::assertLessThan(10, count($this->spy->sent), 'the throttle caps how many links are sent');
        self::assertNotSame([], $this->spy->sent, 'but the first few go through');
    }

    public function test_a_delivery_failure_still_looks_the_same(): void
    {
        $this->createUser('admin', 'ada@test.local');
        $this->spy->fail = true;

        $resp = $this->post('/admin/forgot', ['email' => 'ada@test.local']);

        // The provider blew up, but the user sees the identical generic response.
        self::assertStringContainsString('a reset link is on its way', $resp->body);
    }
}

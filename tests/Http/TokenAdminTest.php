<?php

declare(strict_types=1);

namespace Nimbus\Tests\Http;

use Nimbus\Api\ApiTokenRepository;

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

        $response = $this->post('/admin/tokens', ['name' => 'CI pipeline', 'expires' => 'never']);

        self::assertSame(200, $response->status, 'the secret is rendered, not redirected');
        self::assertNull($response->header('Location'), 'a secret must never travel in a redirect');

        self::assertSame(1, preg_match('/nbt_[0-9a-f]{40}/', $response->body, $m), 'the plaintext is shown once');
        // The shown secret is the real, working token.
        $token = $this->tokens->findByPlaintext($m[0]);
        self::assertNotNull($token);
        self::assertSame('CI pipeline', $token->name);
    }

    public function test_the_secret_never_reappears_on_a_later_page_load(): void
    {
        $this->actingAs('admin');
        $plain = $this->extractSecret($this->post('/admin/tokens', ['name' => 'Once', 'expires' => 'never'])->body);

        self::assertStringNotContainsString($plain, $this->get('/admin/tokens')->body, 'a reload must not re-reveal the secret');
    }

    public function test_an_expiry_preset_is_applied(): void
    {
        $this->actingAs('admin');
        $plain = $this->extractSecret($this->post('/admin/tokens', ['name' => 'Temp', 'expires' => '30d'])->body);

        self::assertNotNull($this->tokens->findByPlaintext($plain)->expiresAt, 'a preset expiry is stored');
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

    private function extractSecret(string $body): string
    {
        self::assertSame(1, preg_match('/nbt_[0-9a-f]{40}/', $body, $m), 'expected a minted secret in the response');
        return $m[0];
    }
}

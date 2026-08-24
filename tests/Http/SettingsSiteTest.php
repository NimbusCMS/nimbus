<?php

declare(strict_types=1);

namespace Nimbus\Tests\Http;

use Nimbus\Settings\SettingsRepository;

/**
 * The admin **site settings** form (`site.home`, `site.description`) — the
 * write side of the DB-backed settings store.
 *
 * The security invariants this locks in: writes need `settings:write` (a
 * management capability, so a content `*:write` scope can never reach it), the
 * write is CSRF-guarded, the typed registry is the allow-list (an unregistered
 * key is never persisted — no over-posting), every value is validated before any
 * is stored (a bad home handle or an over-long description writes nothing), and
 * the stored value is echoed back into the form escaped.
 */
final class SettingsSiteTest extends HttpTestCase
{
    /** @return array<string,string> */
    private function stored(): array
    {
        return (new SettingsRepository($this->db))->all();
    }

    public function test_the_site_form_shows_only_for_settings_write(): void
    {
        $this->actingAs('admin');
        $body = $this->get('/admin/settings')->body;
        self::assertStringContainsString('Save site settings', $body);
        self::assertStringContainsString('Site description', $body);

        // A login-only user (no management capability) sees the theme picker but
        // not the site-settings form.
        $this->resetSession();
        $this->actingWithCapabilities([]);
        self::assertStringNotContainsString('Save site settings', $this->get('/admin/settings')->body);
    }

    public function test_admin_can_save_home_and_description(): void
    {
        $this->makeCollection('blog');
        $this->actingAs('admin');

        $resp = $this->post('/admin/settings/site', ['settings' => [
            'site.home'        => 'blog',
            'site.description' => 'A small, modern CMS.',
        ]]);

        $this->assertRedirectsTo($resp, '/admin/settings?flash=site');
        $stored = $this->stored();
        self::assertSame('blog', $stored['site.home'] ?? null);
        self::assertSame('A small, modern CMS.', $stored['site.description'] ?? null);
    }

    /** A1 — an unregistered key has nowhere to land: the write loop is registry-driven. */
    public function test_an_unregistered_key_is_never_written(): void
    {
        $this->actingAs('admin');

        $this->post('/admin/settings/site', ['settings' => [
            'site.description' => 'ok',
            'role'             => 'admin',
            'evil.key'         => 'x',
        ]]);

        $stored = $this->stored();
        self::assertArrayNotHasKey('role', $stored);
        self::assertArrayNotHasKey('evil.key', $stored);
        self::assertSame('ok', $stored['site.description'] ?? null);
    }

    /** A3 — a home handle that names no collection is rejected; nothing is stored. */
    public function test_a_bogus_home_handle_is_rejected(): void
    {
        $this->actingAs('admin');

        $resp = $this->post('/admin/settings/site', ['settings' => [
            'site.home'        => 'ghost',
            'site.description' => 'x',
        ]]);

        // SUP-4: a failed save re-renders the form (200) with the per-field
        // message — no redirect, so nothing the operator typed is lost, and no
        // text is round-tripped through the URL.
        self::assertSame(200, $resp->status);
        self::assertStringContainsString('No collection has that handle.', $resp->body);
        self::assertSame([], $this->stored(), 'a failed validation writes nothing at all');
    }

    /** A7 — an over-long description is rejected (bounded against meta/storage abuse). */
    public function test_an_over_long_description_is_rejected(): void
    {
        $this->actingAs('admin');

        $resp = $this->post('/admin/settings/site', ['settings' => [
            'site.description' => str_repeat('a', 501),
        ]]);

        self::assertSame(200, $resp->status);
        self::assertStringContainsString('under 500 characters', $resp->body);
        self::assertSame([], $this->stored());
    }

    /** SUP-2 — a control-char title is rejected (it would poison the mail subject and DoS recovery). */
    public function test_a_title_with_control_characters_is_rejected(): void
    {
        $this->actingAs('admin');

        // A CRLF, and a lone control byte — both must be refused, nothing stored.
        foreach (["Nimbus\r\nBcc: e", "Nimbus\x01"] as $bad) {
            $resp = $this->post('/admin/settings/site', ['settings' => ['site.title' => $bad]]);
            self::assertSame(200, $resp->status);
            self::assertStringContainsString('control characters', $resp->body);
            self::assertSame([], $this->stored(), 'a control-char title writes nothing');
        }
    }

    /** A4 — writing needs settings:write; a content writer is refused. */
    public function test_saving_requires_settings_write(): void
    {
        $this->actingWithCapabilities(['posts:write']);

        $resp = $this->post('/admin/settings/site', ['settings' => ['site.description' => 'sneaky']]);

        $this->assertRedirectsTo($resp, '/admin/settings');
        self::assertSame([], $this->stored());
    }

    /** A4 — settings is management, so the content `*:write` wildcard cannot reach it. */
    public function test_the_write_wildcard_does_not_reach_settings(): void
    {
        $this->actingWithCapabilities(['*:write']);

        $resp = $this->post('/admin/settings/site', ['settings' => ['site.description' => 'sneaky']]);

        $this->assertRedirectsTo($resp, '/admin/settings');
        self::assertSame([], $this->stored());
    }

    /** A5 — the save is CSRF-guarded. */
    public function test_the_save_is_csrf_guarded(): void
    {
        $this->actingAs('admin');

        $this->postWithoutCsrf('/admin/settings/site', ['settings' => ['site.description' => 'x']]);

        self::assertSame([], $this->stored());
    }

    /** A2 — a stored hostile description is echoed back into the form escaped. */
    public function test_the_admin_form_escapes_the_stored_description(): void
    {
        (new SettingsRepository($this->db))->set('site.description', '"><script>alert(1)</script>');
        $this->actingAs('admin');

        $body = $this->get('/admin/settings')->body;
        self::assertStringNotContainsString('<script>alert(1)', $body);
        self::assertStringContainsString('&lt;script&gt;', $body);
    }

    // ------------------------------------------------------------- site title

    public function test_the_site_form_includes_the_title_field(): void
    {
        $this->actingAs('admin');
        $body = $this->get('/admin/settings')->body;
        self::assertStringContainsString('Site title', $body);
        self::assertStringContainsString('name="settings[site.title]"', $body);
    }

    public function test_admin_can_save_the_title_and_the_shell_reflects_it(): void
    {
        $this->actingAs('admin');

        $resp = $this->post('/admin/settings/site', ['settings' => ['site.title' => 'Danmat Studio']]);

        $this->assertRedirectsTo($resp, '/admin/settings?flash=site');
        self::assertSame('Danmat Studio', $this->stored()['site.title'] ?? null);
        // The resolved title rides the admin shell (title + brand), not the .env default.
        self::assertStringContainsString('Danmat Studio', $this->get('/admin')->body);
    }

    public function test_a_blank_title_is_rejected(): void
    {
        $this->actingAs('admin');

        $resp = $this->post('/admin/settings/site', ['settings' => ['site.title' => '   ']]);

        self::assertSame(200, $resp->status);
        self::assertStringContainsString('A site title is required.', $resp->body);
        self::assertArrayNotHasKey('site.title', $this->stored());
    }

    public function test_an_over_long_title_is_rejected(): void
    {
        $this->actingAs('admin');

        $resp = $this->post('/admin/settings/site', ['settings' => ['site.title' => str_repeat('a', 81)]]);

        self::assertSame(200, $resp->status);
        self::assertStringContainsString('under 80 characters', $resp->body);
        self::assertArrayNotHasKey('site.title', $this->stored());
    }

    /**
     * SUP-4 — a partial failure re-renders (200) with the per-field message,
     * preserves the operator's other input, and writes NOTHING (all-or-nothing).
     * The redirect-with-generic-flash path is gone, so no failing-field text is
     * lost and none is round-tripped through the URL (the ADMIN-10 class).
     */
    public function test_a_partial_failure_re_renders_with_errors_and_writes_nothing(): void
    {
        $this->actingAs('admin');

        $resp = $this->post('/admin/settings/site', ['settings' => [
            'site.title'       => 'A perfectly good title',   // valid
            'site.description' => str_repeat('a', 501),        // invalid
        ]]);

        self::assertSame(200, $resp->status, 're-render, not a redirect');
        self::assertStringContainsString('under 500 characters', $resp->body);
        self::assertStringContainsString('A perfectly good title', $resp->body, 'the valid input survives the re-render');
        self::assertSame([], $this->stored(), 'all-or-nothing: the valid title is not written either');
    }

    /** SUP-4 — a hostile submitted value that comes back in the re-rendered form
     *  is escaped at the sink (the overlaid value goes through View::e). */
    public function test_a_hostile_submitted_value_is_escaped_in_the_re_render(): void
    {
        $this->actingAs('admin');

        // Invalid (too long) AND hostile — it returns in the textarea, escaped.
        $hostile = '"><script>alert(1)</script>' . str_repeat('a', 500);
        $resp = $this->post('/admin/settings/site', ['settings' => ['site.description' => $hostile]]);

        self::assertSame(200, $resp->status);
        self::assertStringNotContainsString('<script>alert(1)', $resp->body);
        self::assertStringContainsString('&lt;script&gt;', $resp->body);
    }

    /** A1 (escape-lock) — a hostile stored title is escaped in the admin shell. */
    public function test_a_hostile_title_is_escaped_in_the_admin_shell(): void
    {
        (new SettingsRepository($this->db))->set('site.title', '"><script>alert(1)</script>');
        $this->actingAs('admin');

        $body = $this->get('/admin')->body;
        self::assertStringNotContainsString('<script>alert(1)', $body);
        self::assertStringContainsString('&lt;script&gt;', $body);
    }

    /** Unset → the `.env`/config default (APP_NAME=NimbusCMS in the test env). */
    public function test_the_title_defaults_to_the_config_value_when_unset(): void
    {
        self::assertArrayNotHasKey('site.title', $this->stored());
        $this->actingAs('admin');

        self::assertStringContainsString('NimbusCMS', $this->get('/admin')->body);
    }
}

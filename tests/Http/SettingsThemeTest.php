<?php

declare(strict_types=1);

namespace Nimbus\Tests\Http;

use Nimbus\Auth\UserRepository;

/**
 * The per-user theme picker (docs/design/admin-experience.md).
 *
 * A personal preference: any signed-in user sets their OWN admin skin (no
 * capability gate). The security properties: the slug is allow-listed at write
 * AND render (so it can only ever be a known theme in `<html data-theme>`), the
 * write targets only the session user's own row (no cross-user write), and the
 * change is CSRF-guarded.
 */
final class SettingsThemeTest extends HttpTestCase
{
    private UserRepository $users;

    protected function setUp(): void
    {
        parent::setUp();
        $this->users = new UserRepository($this->db);
    }

    public function test_the_settings_page_shows_the_theme_picker(): void
    {
        $this->actingAs('admin');
        $body = $this->get('/admin/settings')->body;

        self::assertSame(200, $this->get('/admin/settings')->status);
        self::assertStringContainsString('Save theme', $body);
        self::assertStringContainsString('Nocturne', $body);
    }

    public function test_choosing_a_theme_persists_and_renders(): void
    {
        $id = $this->actingAs('admin');

        $this->post('/admin/settings/theme', ['theme' => 'nocturne']);

        self::assertSame('nocturne', $this->users->find($id)?->theme);
        self::assertStringContainsString('data-theme="nocturne"', $this->get('/admin')->body, 'the choice rides every page');
    }

    public function test_switching_back_to_nimbus_reverts(): void
    {
        $id = $this->actingAs('admin');
        $this->post('/admin/settings/theme', ['theme' => 'nocturne']);
        $this->post('/admin/settings/theme', ['theme' => 'nimbus']);

        self::assertSame('nimbus', $this->users->find($id)?->theme);
        self::assertStringContainsString('data-theme="nimbus"', $this->get('/admin')->body);
    }

    // --------------------------------------------------------- security

    public function test_a_bogus_theme_is_rejected_at_write(): void
    {
        $id = $this->actingAs('admin');

        $this->post('/admin/settings/theme', ['theme' => 'nimbus"><script>alert(1)</script>']);

        self::assertSame('nimbus', $this->users->find($id)?->theme, 'an unknown slug falls back to the default');
        $body = $this->get('/admin')->body;
        self::assertStringContainsString('data-theme="nimbus"', $body);
        self::assertStringNotContainsString('<script>alert(1)', $body, 'no injection reaches the page');
    }

    public function test_a_tampered_stored_theme_still_renders_safely(): void
    {
        $id = $this->actingAs('admin');
        // An out-of-band value (direct DB edit, or a slug removed in a later
        // release): setTheme writes verbatim — the render must allow-list it.
        $this->users->setTheme($id, 'evil"><script>alert(1)</script>');

        $body = $this->get('/admin')->body;

        self::assertStringContainsString('data-theme="nimbus"', $body, 'render allow-lists on the way out');
        self::assertStringNotContainsString('<script>alert(1)', $body, 'and escaping backs it up');
    }

    public function test_theme_change_requires_csrf(): void
    {
        $id = $this->actingAs('admin');

        $this->postWithoutCsrf('/admin/settings/theme', ['theme' => 'nocturne']);

        self::assertNull($this->users->find($id)?->theme, 'no CSRF token, no write');
    }

    public function test_the_write_targets_only_the_acting_user(): void
    {
        $other = $this->createUser('editor', 'other@test.local');
        $me    = $this->actingAs('admin');

        // A crafted id/user_id must be inert — the write uses the session user.
        $this->post('/admin/settings/theme', ['theme' => 'nocturne', 'id' => (string) $other, 'user_id' => (string) $other]);

        self::assertSame('nocturne', $this->users->find($me)?->theme, 'my own theme changed');
        self::assertNull($this->users->find($other)?->theme, 'the other user is untouched — no IDOR');
    }

    public function test_a_read_of_settings_changes_nothing(): void
    {
        $id = $this->actingAs('admin');
        $this->get('/admin/settings');

        self::assertNull($this->users->find($id)?->theme, 'GET is read-only');
    }
}

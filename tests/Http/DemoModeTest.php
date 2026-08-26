<?php

declare(strict_types=1);

namespace Nimbus\Tests\Http;

use Nimbus\Auth\Password;

/**
 * Demo mode (`NIMBUS_DEMO`) — the public, shared, hourly-reset sandbox. The
 * admin shows a persistent banner and change-password is disabled: hidden in the
 * UI AND refused at the handler, so a direct POST can't lock other visitors out
 * between resets. Off by default (the marketing site and real installs are
 * unaffected — covered by the change-password suite running without the flag).
 */
final class DemoModeTest extends HttpTestCase
{
    protected function tearDown(): void
    {
        putenv('NIMBUS_DEMO'); // unset so the flag never leaks into other tests
        parent::tearDown();
    }

    private function enableDemo(): void
    {
        putenv('NIMBUS_DEMO=1');
        $this->rebuildRouter(); // controllers re-read Config::demo() at build time
    }

    public function test_demo_banner_shows_and_change_password_form_is_hidden(): void
    {
        $this->enableDemo();
        $this->actingAs('admin');
        $body = $this->get('/admin/settings')->body;

        self::assertStringContainsString('Live demo', $body, 'the demo banner renders in the admin shell');
        self::assertStringNotContainsString('action="/admin/settings/password"', $body, 'the change-password form is hidden in demo mode');
    }

    public function test_the_banner_is_absent_by_default(): void
    {
        $this->actingAs('admin');
        $body = $this->get('/admin/settings')->body;
        self::assertStringNotContainsString('Live demo', $body);
        self::assertStringContainsString('action="/admin/settings/password"', $body, 'the form is present when not in demo mode');
    }

    public function test_a_direct_change_password_post_is_refused_in_demo_mode(): void
    {
        $this->enableDemo();
        $id = $this->actingAs('admin');
        $before = $this->db->selectOne('SELECT password FROM nb_users WHERE id = :id', ['id' => $id]);
        self::assertNotNull($before);

        $resp = $this->post('/admin/settings/password', [
            'current_password' => 'correct-horse',
            'new_password'     => 'a-brand-new-passphrase',
            'confirm_password' => 'a-brand-new-passphrase',
        ]);

        self::assertStringContainsString('disabled in the live demo', $resp->body);
        $after = $this->db->selectOne('SELECT password FROM nb_users WHERE id = :id', ['id' => $id]);
        self::assertNotNull($after);
        self::assertSame($before['password'], $after['password'], 'the password must not change in demo mode');
        self::assertTrue(Password::verify('correct-horse', (string) $after['password']));
    }
}

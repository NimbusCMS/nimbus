<?php

declare(strict_types=1);

namespace Nimbus\Tests\Http;

use Nimbus\Site\Menus;

/**
 * The admin Menus editor (DB-backed nav): a saved menu overrides the file default,
 * only a settings:write holder may edit it, and — the security-load-bearing part —
 * a menu URL can only ever be a safe scheme, so a link can't carry a javascript:
 * payload onto a public page.
 */
final class MenusTest extends HttpTestCase
{
    private function menus(): Menus
    {
        return new Menus($this->db);
    }

    // ------------------------------------------------------------- the URL guard

    public function test_safe_url_allows_real_link_targets(): void
    {
        foreach (['https://example.com', 'http://x.test/a', '/menu', '/pages/about', '#section', 'mailto:a@b.test', 'tel:+123'] as $ok) {
            self::assertSame($ok, Menus::safeUrl($ok), "$ok should be allowed");
        }
    }

    public function test_safe_url_rejects_script_and_tricks(): void
    {
        foreach (['javascript:alert(1)', 'JavaScript:alert(1)', "java\tscript:alert(1)", 'data:text/html,<script>', 'vbscript:x', '//evil.example', 'relative-no-slash'] as $bad) {
            self::assertNull(Menus::safeUrl($bad), "$bad must be rejected");
        }
    }

    // ------------------------------------------------------------- the store

    public function test_a_saved_menu_overrides_the_file_default(): void
    {
        $this->menus()->save('main', [['label' => 'Shop', 'url' => '/shop']]);
        self::assertSame([['label' => 'Shop', 'url' => '/shop']], $this->menus()->get('main'));
    }

    public function test_save_drops_empty_and_unsafe_items(): void
    {
        $this->menus()->save('main', [
            ['label' => 'Good', 'url' => '/good'],
            ['label' => 'XSS', 'url' => 'javascript:alert(1)'],
            ['label' => '', 'url' => '/no-label'],
            ['label' => 'No URL', 'url' => ''],
        ]);
        self::assertSame([['label' => 'Good', 'url' => '/good']], $this->menus()->get('main'), 'only the safe, complete item survives');
    }

    public function test_a_wrong_shape_row_fails_safe_to_empty(): void
    {
        // The JSON column guarantees valid JSON, but not the right *shape*; a value
        // that isn't a list of {label,url} must read as empty, never throw.
        $this->db->execute('INSERT INTO nb_menus (name, items, updated_at) VALUES (:n, :i, :u)', ['n' => 'main', 'i' => '"not a list"', 'u' => '2026-01-01 00:00:00']);
        self::assertSame([], $this->menus()->get('main'), 'a wrong-shape row reads as empty');
    }

    // ------------------------------------------------------------- the admin route

    public function test_the_editor_requires_settings_write(): void
    {
        $this->actingWithCapabilities(['posts:write']);
        self::assertSame(302, $this->get('/admin/menus')->status, 'a content editor cannot open the menus editor');

        $this->actingAs('admin');
        self::assertStringContainsString('Menus', $this->get('/admin/menus')->body);
    }

    public function test_saving_persists_and_redirects(): void
    {
        $this->actingAs('admin');
        $res = $this->post('/admin/menus', ['menu' => 'main', 'label' => ['Home', 'Blog'], 'url' => ['/', '/posts']]);

        $this->assertRedirects($res, '/admin/menus?msg=saved');
        self::assertSame([['label' => 'Home', 'url' => '/'], ['label' => 'Blog', 'url' => '/posts']], $this->menus()->get('main'));
    }

    public function test_saving_rejects_a_javascript_url(): void
    {
        $this->actingAs('admin');
        $this->post('/admin/menus', ['menu' => 'main', 'label' => ['Evil'], 'url' => ['javascript:alert(document.cookie)']]);

        self::assertSame([], $this->menus()->get('main'), 'a javascript: URL never persists');
    }

    private function rowCount(string $name): int
    {
        return (int) $this->db->selectOne('SELECT COUNT(*) AS c FROM nb_menus WHERE name = :n', ['n' => $name])['c'];
    }

    public function test_saving_requires_csrf(): void
    {
        $this->actingAs('admin');
        $this->postWithoutCsrf('/admin/menus', ['menu' => 'main', 'label' => ['X'], 'url' => ['/x']]);

        self::assertSame(0, $this->rowCount('main'), 'a forged save must not write a row');
    }

    public function test_a_content_editor_cannot_save(): void
    {
        $this->actingWithCapabilities(['*:write']);
        $this->post('/admin/menus', ['menu' => 'main', 'label' => ['X'], 'url' => ['/x']]);

        self::assertSame(0, $this->rowCount('main'), 'settings:write is required to edit menus');
    }

    public function test_an_unknown_menu_name_is_refused(): void
    {
        $this->actingAs('admin');
        $res = $this->post('/admin/menus', ['menu' => 'bogus', 'label' => ['X'], 'url' => ['/x']]);

        $this->assertRedirects($res, '/admin/menus?err=unknown');
        self::assertSame([], $this->menus()->get('bogus'));
    }
}

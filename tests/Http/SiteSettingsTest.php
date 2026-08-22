<?php

declare(strict_types=1);

namespace Nimbus\Tests\Http;

use Nimbus\Settings\SettingsRepository;

/**
 * The public read side of the settings store: the site home and description
 * resolve from `nb_settings` (the DB override) and render on the public site.
 *
 * Locks in: the DB value selects what renders at `/`; a since-deleted home
 * resolves to null and shows the placeholder rather than 500ing; the stored
 * description is escaped at every public meta sink; and with no stored row the
 * root falls back to the file default (backward-compatible, no seed migration).
 */
final class SiteSettingsTest extends HttpTestCase
{
    private function set(string $key, string $value): void
    {
        (new SettingsRepository($this->db))->set($key, $value);
    }

    public function test_the_db_home_selects_what_renders_at_root(): void
    {
        $this->makeCollection('journal');
        $this->set('site.home', 'journal');

        $resp = $this->get('/');

        self::assertSame(200, $resp->status);
        self::assertStringNotContainsString('No home page is configured yet', $resp->body, 'the DB home is used, not the placeholder');
        self::assertStringContainsString('Journal', $resp->body);
    }

    /** A3 — a home handle whose collection was deleted shows the placeholder, never a 500. */
    public function test_a_dangling_home_shows_the_placeholder_not_a_500(): void
    {
        $this->makeCollection('journal');
        $this->set('site.home', 'journal');
        $this->db->execute('DELETE FROM nb_collections WHERE handle = :h', ['h' => 'journal']);

        $resp = $this->get('/');

        self::assertSame(200, $resp->status, 'a dangling home never 500s');
        self::assertStringContainsString('No home page is configured yet', $resp->body);
    }

    /** A2 — a hostile stored description is escaped in the public <meta> tags. */
    public function test_the_site_description_is_escaped_in_public_meta(): void
    {
        $this->makeCollection('journal');
        $this->set('site.home', 'journal');
        $this->set('site.description', '"><script>alert(1)</script>');

        $body = $this->get('/')->body;

        self::assertStringNotContainsString('<script>alert(1)', $body);
        self::assertStringContainsString('&lt;script&gt;', $body);
    }

    /** BC — with no stored settings the root falls back to the file default (no seed migration). */
    public function test_with_no_stored_home_the_root_still_serves(): void
    {
        self::assertSame([], (new SettingsRepository($this->db))->all(), 'no rows to start');

        $resp = $this->get('/');

        self::assertSame(200, $resp->status);
    }
}

<?php

declare(strict_types=1);

namespace Nimbus\Tests\Unit;

use Nimbus\Site\ThemeCatalog;
use PHPUnit\Framework\TestCase;

/**
 * Theme discovery + the allow-list the site-theme picker rests on. A chosen theme
 * becomes a filesystem path, so the containment here (only real, safely-named,
 * installed themes; resolved paths inside the themes dir) is security-relevant.
 */
final class ThemeCatalogTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/nb-themes-' . bin2hex(random_bytes(6));
        mkdir($this->dir);
        $this->makeTheme('starter', '{"name":"Starter","description":"The bundled theme."}');
        $this->makeTheme('cafe', '{"name":"Fern & Kettle"}');
        // A directory without a manifest is not a theme.
        mkdir($this->dir . '/notatheme');
        // A directory whose name is not a safe slug is skipped entirely.
        mkdir($this->dir . '/Bad_Name');
        file_put_contents($this->dir . '/Bad_Name/theme.json', '{"name":"Bad"}');
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    private function makeTheme(string $slug, string $json): void
    {
        mkdir($this->dir . '/' . $slug);
        file_put_contents($this->dir . '/' . $slug . '/theme.json', $json);
    }

    private function catalog(): ThemeCatalog
    {
        return new ThemeCatalog($this->dir);
    }

    public function test_installed_lists_only_slug_dirs_with_a_manifest(): void
    {
        $installed = $this->catalog()->installed();

        self::assertSame(['cafe', 'starter'], array_keys($installed), 'sorted; no manifest-less or non-slug dirs');
        self::assertSame('Fern & Kettle', $installed['cafe']['name']);
        self::assertSame('The bundled theme.', $installed['starter']['description']);
    }

    public function test_a_manifest_without_a_name_falls_back_to_the_slug(): void
    {
        $this->makeTheme('plain', '{}');
        self::assertSame('Plain', $this->catalog()->installed()['plain']['name']);
    }

    public function test_has_is_the_allow_list(): void
    {
        $cat = $this->catalog();
        self::assertTrue($cat->has('starter'));
        self::assertFalse($cat->has('missing'));
        self::assertFalse($cat->has('Bad_Name'), 'an unsafe-slug dir is never installed');
        self::assertFalse($cat->has('../etc'), 'a traversal name is not a theme');
    }

    public function test_dir_for_contains_the_path_and_rejects_traversal(): void
    {
        $cat = $this->catalog();

        $dir = $cat->dirFor('starter');
        self::assertSame(realpath($this->dir . '/starter'), $dir, 'the real, contained theme directory');

        self::assertNull($cat->dirFor('missing'), 'not installed → no directory');
        self::assertNull($cat->dirFor('../etc'), 'traversal → no directory');
        self::assertNull($cat->dirFor('starter/../../etc'), 'nested traversal → no directory');
    }
}

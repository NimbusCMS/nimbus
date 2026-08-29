<?php

declare(strict_types=1);

namespace Nimbus\Tests\Unit;

use Nimbus\Content\CollectionRepository;
use Nimbus\Database\Connection;
use Nimbus\Settings\SettingsRegistry;
use Nimbus\Site\ThemeCatalog;
use PHPUnit\Framework\TestCase;

/**
 * The `site.theme` setting is the write-side allow-list for the theme picker: a
 * chosen theme must be one that is actually installed, so a stale or hostile value
 * (a traversal) can never be stored and then turned into a render path.
 */
final class SettingsThemeTest extends TestCase
{
    private function registry(): SettingsRegistry
    {
        $dir = sys_get_temp_dir() . '/nb-themetest-' . bin2hex(random_bytes(6));
        mkdir($dir . '/starter', 0777, true);
        file_put_contents($dir . '/starter/theme.json', '{"name":"Starter"}');
        // The Connection is never queried — the site.theme validator touches only
        // the ThemeCatalog — so a config-only connection is fine here.
        $conn = new Connection(['host' => 'unused', 'name' => 'unused', 'user' => 'unused', 'pass' => '']);
        return new SettingsRegistry(new CollectionRepository($conn), new ThemeCatalog($dir));
    }

    public function test_site_theme_is_a_registered_theme_typed_setting(): void
    {
        $setting = $this->registry()->find('site.theme');
        self::assertNotNull($setting);
        self::assertSame('theme', $setting->type, 'the admin form renders it as a theme picker');
    }

    public function test_an_installed_theme_validates_and_an_unknown_one_is_refused(): void
    {
        $validate = $this->registry()->find('site.theme')?->validate;
        self::assertNotNull($validate);

        self::assertNull($validate('starter'), 'an installed theme is accepted');
        self::assertNotNull($validate('ghost'), 'an uninstalled theme is refused');
        self::assertNotNull($validate('../etc'), 'a traversal is refused');
    }
}

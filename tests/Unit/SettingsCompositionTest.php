<?php

declare(strict_types=1);

namespace Nimbus\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * SUP-10: the settings store is composed once, in Application, and threaded to
 * every consumer. This drift guard keeps that true — a seventh (eighth, …)
 * hand-rolled `new Settings(...)` in `src/` would silently reintroduce the
 * duplicate-query / out-of-sync-memo problem the consolidation removed. If a new
 * consumer needs the store, it must receive the composed instance (constructor
 * injection, or Application::settings()), not build its own.
 */
final class SettingsCompositionTest extends TestCase
{
    public function test_settings_is_constructed_only_in_the_application_kernel(): void
    {
        $root  = \dirname(__DIR__, 2) . '/src';
        $sites = [];
        foreach ($this->phpFiles($root) as $file) {
            $src = (string) file_get_contents($file);
            if (preg_match('/new\s+Settings\s*\(/', $src) === 1) {
                $sites[] = substr($file, \strlen($root) + 1);
            }
        }

        self::assertSame(
            ['Application.php'],
            $sites,
            'Settings must be composed once in Application (SUP-10) — a new consumer receives the composed instance, it does not build its own.',
        );
    }

    /** @return list<string> */
    private function phpFiles(string $dir): array
    {
        $out = [];
        $it  = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file instanceof \SplFileInfo && $file->getExtension() === 'php') {
                $out[] = $file->getPathname();
            }
        }
        sort($out);
        return $out;
    }
}

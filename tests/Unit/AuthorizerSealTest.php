<?php

declare(strict_types=1);

namespace Nimbus\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * ADR 0015: the plugin-declared management vocabulary is frozen into the
 * Authorizer exactly once, at boot, in Application::loadPlugins — never mutated
 * at request time. `Authorizer::can()` is a pure decision read on hot paths, so a
 * second `useManagement()` caller elsewhere in src/ would reopen the "authorization
 * vocabulary changes mid-request" hole. This drift guard keeps the seal singular.
 * (`reset()` is test-only and asserted separately in AuthorizerTest.)
 */
final class AuthorizerSealTest extends TestCase
{
    public function test_use_management_is_called_only_in_the_application_kernel(): void
    {
        $root  = \dirname(__DIR__, 2) . '/src';
        $sites = [];
        foreach ($this->phpFiles($root) as $file) {
            $src = (string) file_get_contents($file);
            if (preg_match('/Authorizer::useManagement\s*\(/', $src) === 1) {
                $sites[] = substr($file, \strlen($root) + 1);
            }
        }

        self::assertSame(
            ['Application.php'],
            $sites,
            'Authorizer::useManagement must be called once, at boot, in Application (ADR 0015) — the authorization vocabulary is sealed there and never mutated at request time.',
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

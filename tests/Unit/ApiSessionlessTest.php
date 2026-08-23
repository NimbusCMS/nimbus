<?php

declare(strict_types=1);

namespace Nimbus\Tests\Unit;

use Nimbus\Http\Cors;
use PHPUnit\Framework\TestCase;

/**
 * HTTP-3: the kernel starts no PHP session for the API surface (bearer-only, no
 * `$_SESSION`), so no `nimbus_session` cookie is minted there. This guards the
 * two halves of that decision: the shared path predicate that drives the skip,
 * and the invariant it relies on — that nothing under the API actually needs a
 * session, so a future session-dependent addition fails loudly here.
 */
final class ApiSessionlessTest extends TestCase
{
    public function test_is_api_path_recognizes_the_api_surface(): void
    {
        self::assertTrue(Cors::isApiPath('/api/v1/collections'));
        self::assertTrue(Cors::isApiPath('/api/v1/mcp'));

        self::assertFalse(Cors::isApiPath('/admin'));
        self::assertFalse(Cors::isApiPath('/'));
        self::assertFalse(Cors::isApiPath('/posts/hello'));
        self::assertFalse(Cors::isApiPath('/apiansomething'), 'the trailing slash matters — not a prefix-of-word match');
    }

    public function test_the_api_surface_never_touches_the_session(): void
    {
        // The drift guard: the kernel no longer starts a session for /api, so any
        // $_SESSION / session_* use under these trees would be a silent no-op bug.
        $root = \dirname(__DIR__, 2) . '/src';
        foreach (['Api', 'Mcp'] as $dir) {
            foreach ($this->phpFiles($root . '/' . $dir) as $file) {
                $src = (string) file_get_contents($file);
                self::assertDoesNotMatchRegularExpression(
                    '/\$_SESSION|\bsession_(start|id|regenerate_id|destroy|name|set_cookie_params|status)\b/',
                    $src,
                    "The API surface must stay sessionless — {$file} references the session, but the kernel starts none for /api (HTTP-3).",
                );
            }
        }
    }

    /** @return list<string> */
    private function phpFiles(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }
        $files = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
        return $files;
    }
}

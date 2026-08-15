<?php

declare(strict_types=1);

namespace Nimbus\Support;

use Closure;

/**
 * A filesystem cache for rendered public pages.
 *
 * Deliberately tiny and dependency-free: a hashed file per URL, holding the
 * timestamp it was stored plus the HTML. A TTL of 0 disables it entirely (the
 * default), so the whole feature is inert until an operator opts in with a
 * positive `PAGE_CACHE_TTL`.
 *
 * Correctness rests on two things together: the cache is flushed on every
 * content write (via entry events), and the TTL bounds staleness for changes
 * that happen with no write at all — a scheduled entry becoming live as its
 * publish time passes. Neither alone is enough; the pair is.
 *
 * The clock is injectable so expiry is testable without sleeping.
 */
final class PageCache
{
    private Closure $now;

    /** @param (Closure():int)|null $clock */
    public function __construct(
        private string $dir,
        private int $ttl,
        ?Closure $clock = null,
    ) {
        $this->now = $clock ?? static fn (): int => time();
    }

    /** Whether caching is on. When off, get()/put() do nothing. */
    public function enabled(): bool
    {
        return $this->ttl > 0;
    }

    /** Cached HTML for a key, or null when absent, expired, or disabled. */
    public function get(string $key): ?string
    {
        if (!$this->enabled()) {
            return null;
        }
        $file = $this->file($key);
        if (!is_file($file)) {
            return null;
        }
        $raw   = (string) file_get_contents($file);
        $parts = explode("\n", $raw, 2);
        if (count($parts) !== 2) {
            return null;
        }
        if (($this->now)() - (int) $parts[0] >= $this->ttl) {
            @unlink($file);
            return null;
        }
        return $parts[1];
    }

    /** Store HTML for a key. A no-op when disabled or the directory is unwritable. */
    public function put(string $key, string $html): void
    {
        if (!$this->enabled()) {
            return;
        }
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0o775, true) && !is_dir($this->dir)) {
            return;
        }
        // Write to a unique temp file then rename, so a concurrent reader never
        // sees a half-written page.
        $tmp = $this->file($key) . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, ($this->now)() . "\n" . $html, LOCK_EX) === false) {
            return;
        }
        @rename($tmp, $this->file($key));
    }

    /** Drop every cached page. Called on any content write. */
    public function flush(): void
    {
        if (!is_dir($this->dir)) {
            return;
        }
        foreach (glob($this->dir . '/*.cache') ?: [] as $file) {
            @unlink($file);
        }
    }

    /** A hashed path, so an arbitrary URL key can never escape the cache dir. */
    private function file(string $key): string
    {
        return $this->dir . '/' . hash('sha256', $key) . '.cache';
    }
}

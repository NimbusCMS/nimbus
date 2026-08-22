<?php

declare(strict_types=1);

namespace Nimbus\View;

/**
 * Renders plain-PHP templates from the active theme in an isolated scope.
 * render() wraps a template in the theme layout; renderBare() does not. Shared
 * data (current user, app name, nav) is injected into every template.
 */
final class View
{
    /** @param array<string,mixed> $shared */
    public function __construct(
        private string $themePath,
        private array $shared = [],
    ) {
    }

    /**
     * Set a shared global seen by every template AND every nested partial. Use
     * this for a value that must be consistent across the whole render tree
     * (e.g. the resolved site title) — per-render `$data` reaches the top-level
     * template but not the partials it includes, which fall back to the shared
     * set.
     */
    public function share(string $key, mixed $value): void
    {
        $this->shared[$key] = $value;
    }

    /** @param array<string,mixed> $data */
    public function render(string $template, array $data = []): string
    {
        $content = $this->partial($template, $data);
        return $this->partial('layout', array_merge($data, ['__content' => $content]));
    }

    /** @param array<string,mixed> $data */
    public function renderBare(string $template, array $data = []): string
    {
        return $this->partial($template, $data);
    }

    /** @param array<string,mixed> $data */
    public function partial(string $template, array $data = []): string
    {
        $file = $this->file($template);
        if ($file === null) {
            throw new \RuntimeException("Template not found: {$template}");
        }

        $data = array_merge($this->shared, $data, [
            // Helpers every template can use without boilerplate. `partial`
            // includes another template from the same theme — its output is
            // rendered HTML, so echoing it is correct (it is not user data).
            // `e` escapes a value for output.
            'partial' => fn (string $t, array $d = []): string => $this->partial($t, $d),
            'e'       => static fn (?string $v): string => self::e($v),
        ]);

        return (static function () use ($file, $data): string {
            extract($data, EXTR_SKIP);
            ob_start();
            include $file;
            return (string) ob_get_clean();
        })();
    }

    /**
     * Whether the active theme provides a template. Themes use this indirectly:
     * a caller can prefer a specific template (`entry-posts`) and fall back to a
     * generic one (`entry`) when the theme has not specialised it.
     */
    public function exists(string $template): bool
    {
        return $this->file($template) !== null;
    }

    /**
     * Resolve a template name to a readable file inside the theme, or null when
     * it does not exist. The name is restricted to a safe character set so a
     * template name derived from data (e.g. a collection handle) can never walk
     * out of the theme's templates directory.
     */
    private function file(string $template): ?string
    {
        // Slash-separated segments allow nested templates (e.g. collections/form)
        // while forbidding dots — so `..` traversal and absolute paths never
        // resolve, even when a name is derived from data like a collection handle.
        if (preg_match('#^[A-Za-z0-9_-]+(?:/[A-Za-z0-9_-]+)*$#', $template) !== 1) {
            return null;
        }
        $file = $this->themePath . '/templates/' . $template . '.php';
        return is_file($file) ? $file : null;
    }

    public function themePath(): string
    {
        return $this->themePath;
    }

    public static function e(?string $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

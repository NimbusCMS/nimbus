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

    public function render(string $template, array $data = []): string
    {
        $content = $this->partial($template, $data);
        return $this->partial('layout', array_merge($data, ['__content' => $content]));
    }

    public function renderBare(string $template, array $data = []): string
    {
        return $this->partial($template, $data);
    }

    public function partial(string $template, array $data = []): string
    {
        $file = $this->themePath . '/templates/' . $template . '.php';
        if (!is_file($file)) {
            throw new \RuntimeException("Template not found: {$template}");
        }
        $data = array_merge($this->shared, $data);

        return (static function () use ($file, $data): string {
            extract($data, EXTR_SKIP);
            ob_start();
            include $file;
            return (string) ob_get_clean();
        })();
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

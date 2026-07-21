<?php

declare(strict_types=1);

namespace Nimbus\Plugin;

use Throwable;

/**
 * Discovers installed plugins and registers the enabled ones.
 *
 * Discovery is Composer's `installed.json` and nothing else: no directory
 * scanning, no globbing for PHP files, no `include` of anything we did not
 * resolve through the autoloader. A plugin is a Composer package of type
 * `nimbuscms-plugin` that declares its id and class under `extra.nimbus`.
 *
 * That means installing a plugin is a deliberate act at the command line,
 * recorded in composer.lock and reviewable in a diff. The core never downloads
 * or executes code it was not explicitly given.
 *
 *     {
 *       "name": "nimbuscms/markdown",
 *       "type": "nimbuscms-plugin",
 *       "extra": { "nimbus": {
 *         "id": "nimbuscms.markdown",
 *         "plugin": "NimbusCMS\\Markdown\\MarkdownPlugin"
 *       }}
 *     }
 *
 * Every rejection produces a PluginDiagnostic rather than a silent skip.
 */
final class PluginLoader
{
    /** @var list<PluginDiagnostic> */
    private array $diagnostics = [];

    /** @var array<string,string> plugin id => package name, for duplicate detection */
    private array $loaded = [];

    /**
     * @param array<string,bool> $enabled plugin id => enabled; unlisted ids default to $enabledByDefault
     */
    public function __construct(
        private string $installedJsonPath,
        private array $enabled = [],
        private bool $enabledByDefault = true,
    ) {
    }

    /**
     * Register every enabled plugin into the context.
     *
     * @return list<PluginDiagnostic> everything that did not register, and why
     */
    public function load(PluginContext $context): array
    {
        $this->diagnostics = [];
        $this->loaded      = [];

        foreach ($this->packages() as $package) {
            $this->loadPackage($package, $context);
        }
        return $this->diagnostics;
    }

    /** @param array<string,mixed> $package */
    private function loadPackage(array $package, PluginContext $context): void
    {
        $name = (string) ($package['name'] ?? 'unknown package');
        $meta = $package['extra']['nimbus'] ?? null;

        if (!is_array($meta) || !is_string($meta['id'] ?? null) || !is_string($meta['plugin'] ?? null)) {
            $this->fail($name, PluginDiagnostic::INVALID_MANIFEST, 'extra.nimbus must declare a string "id" and "plugin".');
            return;
        }

        $id    = $meta['id'];
        $class = $meta['plugin'];

        if (isset($this->loaded[$id])) {
            $this->fail($name, PluginDiagnostic::DUPLICATE_ID, "Plugin id \"{$id}\" is already provided by {$this->loaded[$id]}.");
            return;
        }
        if (!($this->enabled[$id] ?? $this->enabledByDefault)) {
            $this->diagnostics[] = new PluginDiagnostic($name, PluginDiagnostic::DISABLED, "Plugin \"{$id}\" is disabled by configuration.");
            return;
        }
        if (!class_exists($class)) {
            $this->fail($name, PluginDiagnostic::MISSING_CLASS, "Class {$class} was not found. Is the package autoloaded?");
            return;
        }
        if (!is_subclass_of($class, Plugin::class)) {
            $this->fail($name, PluginDiagnostic::NOT_A_PLUGIN, "Class {$class} does not implement " . Plugin::class . '.');
            return;
        }

        try {
            /** @var Plugin $plugin */
            $plugin = new $class();
            $plugin->register($context);
        } catch (Throwable $e) {
            // A broken plugin must not take the whole admin down, but it must
            // also not fail quietly — the diagnostic is the record.
            $this->fail($name, PluginDiagnostic::REGISTER_FAILED, get_class($e) . ': ' . $e->getMessage());
            return;
        }

        $this->loaded[$id] = $name;
    }

    /**
     * Installed packages of type `nimbuscms-plugin`, in Composer's order.
     *
     * @return list<array<string,mixed>>
     */
    private function packages(): array
    {
        if (!is_file($this->installedJsonPath)) {
            return [];
        }
        $decoded = json_decode((string) file_get_contents($this->installedJsonPath), true);
        if (!is_array($decoded)) {
            return [];
        }
        // Composer 2 nests under "packages"; Composer 1 was a bare list.
        $packages = is_array($decoded['packages'] ?? null) ? $decoded['packages'] : $decoded;

        $plugins = [];
        foreach ($packages as $package) {
            if (is_array($package) && ($package['type'] ?? null) === 'nimbuscms-plugin') {
                $plugins[] = $package;
            }
        }
        return $plugins;
    }

    /** @return array<string,string> plugin id => package name, for the ones that registered */
    public function registered(): array
    {
        return $this->loaded;
    }

    private function fail(string $package, string $reason, string $message): void
    {
        $this->diagnostics[] = new PluginDiagnostic($package, $reason, $message);
    }
}

<?php

declare(strict_types=1);

namespace Nimbus\Plugin;

use Nimbus\Content\FieldTypeRegistry;
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
 * Loading is two-phase, and the split matters:
 *
 *   1. **Validate every manifest first.** Ids are claimed by *installation*,
 *      not by successful registration. Reserving ids only on success would let
 *      a second installed package quietly inherit an id whenever the rightful
 *      owner was disabled or broken — so disabling a plugin could hand its
 *      identity to another package.
 *   2. **Then register, with rollback.** A plugin that registers two types and
 *      throws on the second has its first registration undone, so a plugin
 *      reported as failed is never partially active.
 *
 * Every rejection produces a PluginDiagnostic rather than a silent skip.
 */
final class PluginLoader
{
    /** @var list<PluginDiagnostic> */
    private array $diagnostics = [];

    /** @var array<string,string> plugin id => package name, for the ones that registered */
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
     * Register every enabled plugin into the registry.
     *
     * @return list<PluginDiagnostic> everything that did not register, and why
     */
    public function load(FieldTypeRegistry $fieldTypes): array
    {
        $this->diagnostics = [];
        $this->loaded      = [];

        foreach ($this->validate($this->packages()) as $id => $candidate) {
            $this->register($id, $candidate['package'], $candidate['class'], $fieldTypes);
        }
        return $this->diagnostics;
    }

    /**
     * Phase one: every manifest is checked, and ids are claimed by installation
     * rather than by success. Enabled state is deliberately not consulted here.
     *
     * @param list<array<string,mixed>> $packages
     * @return array<string,array{package:string,class:class-string<Plugin>}>
     */
    private function validate(array $packages): array
    {
        /** @var array<string,array{package:string,class:class-string<Plugin>}> $valid */
        $valid = [];
        /** @var array<string,string> $claimedBy */
        $claimedBy = [];

        foreach ($packages as $package) {
            $name = (string) ($package['name'] ?? 'unknown package');
            $meta = $package['extra']['nimbus'] ?? null;

            if (!is_array($meta) || !is_string($meta['id'] ?? null) || !is_string($meta['plugin'] ?? null)) {
                $this->fail($name, PluginDiagnostic::INVALID_MANIFEST, 'extra.nimbus must declare a string "id" and "plugin".');
                continue;
            }

            $id    = $meta['id'];
            $class = $meta['plugin'];

            if (isset($claimedBy[$id])) {
                // Both packages are rejected on the *second* claim only; the
                // first keeps the id. Two packages fighting over an id is a
                // deployment mistake, and it must not resolve differently
                // depending on which one happens to be enabled.
                $this->fail($name, PluginDiagnostic::DUPLICATE_ID, "Plugin id \"{$id}\" is already claimed by {$claimedBy[$id]}.");
                continue;
            }
            $claimedBy[$id] = $name;

            if (!class_exists($class)) {
                $this->fail($name, PluginDiagnostic::MISSING_CLASS, "Class {$class} was not found. Is the package autoloaded?");
                continue;
            }
            if (!is_subclass_of($class, Plugin::class)) {
                $this->fail($name, PluginDiagnostic::NOT_A_PLUGIN, "Class {$class} does not implement " . Plugin::class . '.');
                continue;
            }

            /** @var class-string<Plugin> $class */
            $valid[$id] = ['package' => $name, 'class' => $class];
        }
        return $valid;
    }

    /**
     * Phase two: instantiate and register, undoing anything a failing plugin
     * managed to register before it threw.
     *
     * @param class-string<Plugin> $class
     */
    private function register(string $id, string $package, string $class, FieldTypeRegistry $fieldTypes): void
    {
        if (!($this->enabled[$id] ?? $this->enabledByDefault)) {
            $this->diagnostics[] = new PluginDiagnostic($package, PluginDiagnostic::DISABLED, "Plugin \"{$id}\" is disabled by configuration.");
            return;
        }

        try {
            (new $class())->register(new PluginContext($fieldTypes, $id));
        } catch (Throwable $e) {
            // Undo whatever landed before the throw, so "failed" in the
            // diagnostics and "inactive" in the application agree.
            $rolledBack = $fieldTypes->forgetProvider($id);
            $detail     = $rolledBack === [] ? '' : ' Rolled back: ' . implode(', ', $rolledBack) . '.';

            // A broken plugin must not take the whole admin down — that is the
            // only place an administrator can go to disable it — but it must
            // also not fail quietly.
            $this->fail($package, PluginDiagnostic::REGISTER_FAILED, get_class($e) . ': ' . $e->getMessage() . $detail);
            return;
        }

        $this->loaded[$id] = $package;
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

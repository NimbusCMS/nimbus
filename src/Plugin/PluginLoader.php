<?php

declare(strict_types=1);

namespace Nimbus\Plugin;

use Nimbus\Support\CoreEvents;
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
 *       "version": "0.1.0",
 *       "extra": { "nimbus": {
 *         "id": "nimbuscms.markdown",
 *         "plugin": "NimbusCMS\\Markdown\\MarkdownPlugin",
 *         "name": "Markdown"
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
 * Every discovered package produces a PluginStatus (for the admin page), and
 * every rejection additionally produces a PluginDiagnostic rather than a silent
 * skip.
 */
final class PluginLoader
{
    /** @var list<PluginDiagnostic> */
    private array $diagnostics = [];

    /** @var list<PluginStatus> one per discovered package, in Composer order */
    private array $statuses = [];

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
     * Register every enabled plugin into the shared registries.
     *
     * @return list<PluginDiagnostic> everything that did not register, and why
     */
    public function load(PluginCapabilities $capabilities): array
    {
        $this->diagnostics = [];
        $this->statuses    = [];
        $this->loaded      = [];

        foreach ($this->validate($this->packages()) as $id => $candidate) {
            $this->register($id, $candidate, $capabilities);
        }
        return $this->diagnostics;
    }

    /**
     * Phase one: every manifest is checked, and ids are claimed by installation
     * rather than by success. Enabled state is deliberately not consulted here.
     *
     * @param list<array<string,mixed>> $packages
     * @return array<string,array{package:string,class:class-string<Plugin>,version:string,name:string,official:bool}>
     */
    private function validate(array $packages): array
    {
        /** @var array<string,array{package:string,class:class-string<Plugin>,version:string,name:string,official:bool}> $valid */
        $valid = [];
        /** @var array<string,string> $claimedBy */
        $claimedBy = [];

        foreach ($packages as $package) {
            $name     = (string) ($package['name'] ?? 'unknown package');
            $version  = (string) ($package['version'] ?? 'dev');
            $official = str_starts_with($name, 'nimbuscms/');
            $meta     = $package['extra']['nimbus'] ?? null;

            if (!is_array($meta) || !is_string($meta['id'] ?? null) || !is_string($meta['plugin'] ?? null)) {
                $this->reject($name, '', $this->displayName($name, $meta), $version, $official, PluginStatus::INVALID, PluginDiagnostic::INVALID_MANIFEST, 'extra.nimbus must declare a string "id" and "plugin".');
                continue;
            }

            $id      = $meta['id'];
            $class   = $meta['plugin'];
            $display = $this->displayName($name, $meta);

            // A well-formed id is a loader invariant: `core` defeats field-type
            // rollback (FieldTypeRegistry::forgetProvider('core') no-ops), a colon
            // collides migration names (pluginId:name), an empty id threads
            // everywhere, and an over-long id overflows nb_migrations.migration
            // (VARCHAR(191)) → a 500 at migrate. One gate closes all four.
            // `nb` (and `nb_`/`nb.`/`nb-` prefixes) is core's reserved namespace:
            // a plugin id of `nb_stats` would name tables `nb_stats_*`, colliding
            // with core's `nb_*` and tripping the migration lint (FU-11/FU-18).
            if ($id === 'core' || strlen($id) > 64
                || preg_match('/^[a-z0-9][a-z0-9._-]*$/', $id) !== 1
                || preg_match('/^nb([._-]|$)/', $id) === 1) {
                $this->reject($name, $id, $display, $version, $official, PluginStatus::INVALID, PluginDiagnostic::INVALID_MANIFEST, 'A plugin id must be lowercase letters, digits, dot/dash/underscore (≤64 chars); cannot be "core"; and cannot start with the reserved "nb" namespace.');
                continue;
            }

            // A plugin may now emit its own events (ADR 0014), always namespaced
            // under its id verbatim. So an id rooted in a namespace core dispatches
            // in (`entry`/`api`/`auth`/`request`) would let a plugin forge a core
            // event — an `entry`-rooted plugin emitting `entry.saved` into core's
            // revision/audit listeners. Reserving those roots at the identity level
            // makes forgery structurally impossible, so emit() needs no per-call
            // check. Derived from CoreEvents so it can't drift behind a new event.
            $root = strstr($id, '.', true);
            if (in_array($root === false ? $id : $root, CoreEvents::reservedRoots(), true)) {
                $this->reject($name, $id, $display, $version, $official, PluginStatus::INVALID, PluginDiagnostic::INVALID_MANIFEST, 'A plugin id cannot be, or start with, a namespace core dispatches events in (' . implode(', ', CoreEvents::reservedRoots()) . ') — a plugin could otherwise forge a core event.');
                continue;
            }

            if (isset($claimedBy[$id])) {
                // Both packages are rejected on the *second* claim only; the
                // first keeps the id. Two packages fighting over an id is a
                // deployment mistake, and it must not resolve differently
                // depending on which one happens to be enabled.
                $this->reject($name, $id, $display, $version, $official, PluginStatus::DUPLICATE_ID, PluginDiagnostic::DUPLICATE_ID, "Plugin id \"{$id}\" is already claimed by {$claimedBy[$id]}.");
                continue;
            }
            $claimedBy[$id] = $name;

            if (!class_exists($class)) {
                $this->reject($name, $id, $display, $version, $official, PluginStatus::INVALID, PluginDiagnostic::MISSING_CLASS, "Class {$class} was not found. Is the package autoloaded?");
                continue;
            }
            if (!is_subclass_of($class, Plugin::class)) {
                $this->reject($name, $id, $display, $version, $official, PluginStatus::INVALID, PluginDiagnostic::NOT_A_PLUGIN, "Class {$class} does not implement " . Plugin::class . '.');
                continue;
            }

            /** @var class-string<Plugin> $class */
            $valid[$id] = ['package' => $name, 'class' => $class, 'version' => $version, 'name' => $display, 'official' => $official];
        }
        return $valid;
    }

    /**
     * Phase two: instantiate and register, undoing anything a failing plugin
     * managed to register before it threw.
     *
     * @param array{package:string,class:class-string<Plugin>,version:string,name:string,official:bool} $candidate
     */
    private function register(string $id, array $candidate, PluginCapabilities $capabilities): void
    {
        $package  = $candidate['package'];
        $enabled  = $this->enabled[$id] ?? $this->enabledByDefault;

        if (!$enabled) {
            $this->statuses[] = new PluginStatus($id, $package, $candidate['name'], $candidate['version'], false, PluginStatus::DISABLED, 'Disabled in configuration.', $candidate['official']);
            $this->diagnostics[] = new PluginDiagnostic($package, PluginDiagnostic::DISABLED, "Plugin \"{$id}\" is disabled by configuration.");
            return;
        }

        try {
            (new $candidate['class']())->register(new PluginContext($capabilities, $id));
        } catch (Throwable $e) {
            // Undo whatever landed before the throw, so "failed" in the
            // diagnostics and "inactive" in the application agree.
            $capabilities->head->forgetProvider($id);
            $capabilities->viewData->forgetProvider($id);
            $capabilities->events->forgetProvider($id);
            $capabilities->migrations->forgetProvider($id);
            $capabilities->adminPages->forgetProvider($id);
            $capabilities->maintenance->forgetProvider($id);
            $capabilities->skills->forgetProvider($id);
            $capabilities->capabilities->forgetProvider($id);
            $capabilities->mcpToolsets->forgetProvider($id);
            $capabilities->routes->forgetProvider($id);
            $capabilities->pageSections->forgetProvider($id);
            $capabilities->services->forgetProvider($id);
            $rolledBack = $capabilities->fieldTypes->forgetProvider($id);
            $detail     = $rolledBack === [] ? '' : ' Rolled back: ' . implode(', ', $rolledBack) . '.';
            $message    = $e->getMessage() . $detail;

            // A broken plugin must not take the whole admin down — that is the
            // only place an administrator can go to disable it — but it must
            // also not fail quietly. The class name is kept out of the UI
            // message; the full exception is logged by the application.
            $this->statuses[] = new PluginStatus($id, $package, $candidate['name'], $candidate['version'], true, PluginStatus::FAILED, $message, $candidate['official']);
            $this->diagnostics[] = new PluginDiagnostic($package, PluginDiagnostic::REGISTER_FAILED, get_class($e) . ': ' . $message);
            return;
        }

        $this->loaded[$id] = $package;
        $this->statuses[]  = new PluginStatus($id, $package, $candidate['name'], $candidate['version'], true, PluginStatus::HEALTHY, '', $candidate['official']);
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

    /**
     * One status per discovered package, in Composer order. Populated by load().
     *
     * @return list<PluginStatus>
     */
    public function statuses(): array
    {
        return $this->statuses;
    }

    /** @param array<string,mixed>|null $meta */
    private function displayName(string $package, mixed $meta): string
    {
        if (is_array($meta) && is_string($meta['name'] ?? null) && trim($meta['name']) !== '') {
            return $meta['name'];
        }
        // Humanise the last path segment: "nimbuscms/plugin-markdown" -> "Plugin Markdown".
        $slug = str_contains($package, '/') ? substr((string) strrchr($package, '/'), 1) : $package;
        return ucwords(str_replace(['-', '_'], ' ', $slug));
    }

    private function reject(string $package, string $id, string $display, string $version, bool $official, string $state, string $reason, string $message): void
    {
        $this->statuses[]    = new PluginStatus($id, $package, $display, $version, false, $state, $message, $official);
        $this->diagnostics[] = new PluginDiagnostic($package, $reason, $message);
    }
}

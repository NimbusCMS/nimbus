<?php

declare(strict_types=1);

namespace Nimbus\Auth;

use InvalidArgumentException;
use Nimbus\Support\Str;

/**
 * The grantable **management** capabilities plugins declare (ADR 0015, H2a).
 *
 * Stock is money, so an Inventory plugin needs a capability an admin can grant to
 * a role and a token can carry, that the broad content wildcard `*:write` can
 * **never** reach — a management capability, like `schema`/`tokens`. Core's set
 * ({@see Authorizer::MANAGEMENT}) is a fixed const; this registry is how a plugin
 * adds its own to that vocabulary without forking core.
 *
 * The one load-bearing rule: a capability's **resource is the plugin id, verbatim**
 * (bound by the loader, never chosen by the plugin), and that id **must be
 * namespaced** — contain a dot, like `vendor.name`. That single requirement makes
 * every collision structurally impossible rather than checked:
 *
 * - **Two plugins can't collide** — plugin ids are unique (PluginLoader).
 * - **A plugin can't shadow a core management name** — `schema`/`media`/… are all
 *   flat, so a dotted id can never equal one.
 * - **A plugin resource can't collide with a content collection handle** — the
 *   FU-4 management-namespace-confusion class. `Str::handle()` folds any dot to
 *   `_`, so a dotted resource is *not a possible handle*: no collection can ever
 *   be named `vendor.name`, in either direction (a new collection, or a plugin
 *   installed onto a site that already has collections). This is the same
 *   "namespace verbatim" move that made plugin event emit safe (ADR 0014).
 *
 * The registry is populated during plugin load (like every other registrar),
 * then its resource set is frozen into {@see Authorizer} for the process. A
 * failing plugin's declaration is rolled back with its other registrations.
 */
final class CapabilityRegistry
{
    private const ACTIONS = ['read', 'write'];

    /** @var array<string,array{label:string,actions:list<string>}> resource (=plugin id) => definition */
    private array $declared = [];

    /**
     * Declare a plugin's grantable management capability. Called by the loader-bound
     * {@see \Nimbus\Plugin\CapabilitiesRegistrar}, which supplies the plugin id.
     *
     * @param string       $pluginId the resource — the plugin's own id, verbatim
     * @param list<string> $actions  a non-empty subset of {read, write}
     *
     * @throws InvalidArgumentException on a flat id, a bad label, bad actions, or a
     *                                  duplicate — each fails the plugin's load.
     */
    public function declare(string $pluginId, string $label, array $actions): void
    {
        if (!str_contains($pluginId, '.')) {
            throw new InvalidArgumentException(
                "Plugin \"{$pluginId}\" must use a namespaced id (e.g. \"vendor.name\") to declare a capability: "
                . 'a flat id could collide with a content collection handle or a core management name.',
            );
        }
        // Defence in depth behind the dot: a dotted id folds to a different handle
        // and cannot equal a flat core management name, but assert it anyway so a
        // future flat-id path can never silently shadow core.
        if (in_array($pluginId, Authorizer::MANAGEMENT, true) || $pluginId === 'admin') {
            throw new InvalidArgumentException("Capability \"{$pluginId}\" collides with a core management capability.");
        }
        if (Str::handle($pluginId) === $pluginId) {
            throw new InvalidArgumentException("Capability \"{$pluginId}\" must be namespaced so it cannot be a collection handle.");
        }

        $label = trim($label);
        if ($label === '' || mb_strlen($label) > 80) {
            throw new InvalidArgumentException('A capability label must be 1–80 characters.');
        }

        $actions = array_values(array_unique($actions));
        if ($actions === [] || array_diff($actions, self::ACTIONS) !== []) {
            throw new InvalidArgumentException('Capability actions must be a non-empty subset of {read, write}.');
        }

        if (isset($this->declared[$pluginId])) {
            throw new InvalidArgumentException("Capability \"{$pluginId}\" is already declared.");
        }

        $this->declared[$pluginId] = ['label' => $label, 'actions' => $actions];
    }

    /**
     * The plugin-declared management resources — the set {@see Authorizer} treats
     * as wildcard-immune. Each is a plugin id.
     *
     * @return list<string>
     */
    public function managementResources(): array
    {
        return array_keys($this->declared);
    }

    /**
     * Grantable `resource:action` strings → human label, for the roles grant UI
     * and its server-side allow-list. Each declared action is an **independent**
     * grant — like core management, a plugin `write` does not imply `read`
     * ({@see Authorizer::can()} treats management read/write separately) — so the
     * checklist lists exactly the declared actions, one row each.
     *
     * @return array<string,string>
     */
    public function grantable(): array
    {
        $out = [];
        foreach ($this->declared as $resource => $def) {
            foreach ($def['actions'] as $action) {
                $verb                         = $action === 'read' ? 'view' : 'manage';
                $out["{$resource}:{$action}"] = "{$def['label']}: {$verb}";
            }
        }
        return $out;
    }

    /** Remove a plugin's declaration — used on plugin-load rollback. */
    public function forgetProvider(string $pluginId): void
    {
        unset($this->declared[$pluginId]);
    }
}

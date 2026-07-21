<?php

declare(strict_types=1);

namespace Nimbus\Plugin;

/**
 * The plugin contract.
 *
 * One method, on purpose. A plugin declares what it provides and returns.
 * There is no boot(), activate(), install() or uninstall() yet, because those
 * are four different lifecycles and none of them has a concrete requirement
 * behind it. Adding a method later is easy; removing one from a published
 * interface is not.
 *
 * register() runs on every application boot, for every enabled plugin. It must
 * be cheap and side-effect free beyond registration: no queries, no I/O, no
 * output. Whether a plugin is enabled is decided before register() is called.
 *
 * See docs/adr/0001-plugin-contract.md for the reasoning.
 */
interface Plugin
{
    public function register(PluginContext $context): void;
}

<?php

declare(strict_types=1);

namespace Nimbus\Plugin;

/**
 * Everything a plugin is allowed to touch.
 *
 * Four capabilities today: field types, head contributions (ADR 0004), event
 * subscription, and migrations for the plugin's own tables (ADR 0005). Each was
 * added alongside a plugin that concretely needed it — field types by the
 * built-in types and Markdown, head contributions by plugin-seo, events and
 * migrations by plugin-analytics. Routes, permissions and admin navigation get
 * added the same way, one at a time, because a capability published without a
 * consumer is a guess that becomes a commitment.
 *
 * A context is built per plugin, so the plugin's id is bound to whatever it
 * registers and cannot be spoofed.
 *
 * Deliberately absent, and staying absent:
 *
 * - Application — hands over the kernel, and every internal becomes API
 * - controllers — internal structure; #11 moved half of one, and no plugin
 *   should have been able to notice
 * - the database connection and repositories — direct table access bypasses
 *   the services owning transactions, validation, slugs and events
 * - session and authentication internals — auth state is core's to own
 * - a generic get() or service locator — that is not an API, it is the
 *   absence of one, and it makes every refactor a breaking change
 *
 * Plugins receive capabilities, never the objects that implement them.
 */
final class PluginContext
{
    private FieldTypeRegistrar $fieldTypes;
    private HeadRegistrar $head;
    private EventRegistrar $events;
    private MigrationRegistrar $migrations;

    public function __construct(PluginCapabilities $capabilities, private string $pluginId)
    {
        $this->fieldTypes = new FieldTypeRegistrar($capabilities->fieldTypes, $pluginId);
        $this->head       = new HeadRegistrar($capabilities->head, $pluginId);
        $this->events     = new EventRegistrar($capabilities->events, $pluginId);
        $this->migrations = new MigrationRegistrar($capabilities->migrations, $pluginId);
    }

    /** Register field types. Registrations are stamped with this plugin's id. */
    public function fieldTypes(): FieldTypeRegistrar
    {
        return $this->fieldTypes;
    }

    /** Register document-head contributors. Stamped with this plugin's id. */
    public function head(): HeadRegistrar
    {
        return $this->head;
    }

    /** Subscribe to events (see CoreEvents). Stamped with this plugin's id. */
    public function events(): EventRegistrar
    {
        return $this->events;
    }

    /** Declare migrations for the plugin's own tables. Stamped with its id. */
    public function migrations(): MigrationRegistrar
    {
        return $this->migrations;
    }

    /** The id this plugin was loaded under, from its Composer manifest. */
    public function pluginId(): string
    {
        return $this->pluginId;
    }
}

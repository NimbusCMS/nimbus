<?php

declare(strict_types=1);

namespace Nimbus\Plugin;

use Nimbus\Content\FieldTypeRegistry;

/**
 * Everything a plugin is allowed to touch.
 *
 * Exactly one capability today: field types. That is not an oversight — it is
 * the only extension surface with a proven first-party consumer (nine built-in
 * types) and a reference plugin exercising it. Routes, events, permissions,
 * migrations and admin navigation get added one at a time, each alongside a
 * plugin that actually needs it, because a capability published without a
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

    public function __construct(FieldTypeRegistry $fieldTypes, private string $pluginId)
    {
        $this->fieldTypes = new FieldTypeRegistrar($fieldTypes, $pluginId);
    }

    /** Register field types. Registrations are stamped with this plugin's id. */
    public function fieldTypes(): FieldTypeRegistrar
    {
        return $this->fieldTypes;
    }

    /** The id this plugin was loaded under, from its Composer manifest. */
    public function pluginId(): string
    {
        return $this->pluginId;
    }
}

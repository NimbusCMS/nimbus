<?php

declare(strict_types=1);

namespace Nimbus\Plugin;

use Nimbus\Content\FieldTypeRegistry;
use Nimbus\Site\HeadContributorRegistry;

/**
 * Everything a plugin is allowed to touch.
 *
 * Two capabilities today: field types, and head contributions (ADR 0004). Each
 * was added alongside a plugin that concretely needed it — field types by the
 * built-in types and the Markdown plugin, head contributions by plugin-seo.
 * Routes, events, permissions, migrations and admin navigation get added the
 * same way, one at a time, because a capability published without a consumer is
 * a guess that becomes a commitment.
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

    public function __construct(FieldTypeRegistry $fieldTypes, HeadContributorRegistry $head, private string $pluginId)
    {
        $this->fieldTypes = new FieldTypeRegistrar($fieldTypes, $pluginId);
        $this->head       = new HeadRegistrar($head, $pluginId);
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

    /** The id this plugin was loaded under, from its Composer manifest. */
    public function pluginId(): string
    {
        return $this->pluginId;
    }
}

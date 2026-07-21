<?php

declare(strict_types=1);

namespace Nimbus\Plugin;

use Nimbus\Content\FieldTypeRegistry;

/**
 * Everything a plugin is allowed to touch.
 *
 * Exactly one capability today: field types. That is not an oversight — it is
 * the only extension surface with a proven first-party consumer (nine built-in
 * types) and a reference plugin about to exercise it. Routes, events,
 * permissions, migrations and admin navigation get added one at a time, each
 * alongside a plugin that actually needs it, because a capability published
 * without a consumer is a guess that becomes a commitment.
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
    public function __construct(
        private FieldTypeRegistry $fieldTypes,
    ) {
    }

    /**
     * The application's field-type registry — the same instance the field
     * builder, entry forms, validator and (later) API serializer read from.
     */
    public function fieldTypes(): FieldTypeRegistry
    {
        return $this->fieldTypes;
    }
}

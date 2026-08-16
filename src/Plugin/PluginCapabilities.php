<?php

declare(strict_types=1);

namespace Nimbus\Plugin;

use Nimbus\Admin\AdminPageRegistry;
use Nimbus\Content\FieldTypeRegistry;
use Nimbus\Database\Connection;
use Nimbus\Database\MigrationRegistry;
use Nimbus\Site\HeadContributorRegistry;
use Nimbus\Support\EventDispatcher;
use Nimbus\Support\MaintenanceRegistry;

/**
 * The shared registries a plugin registers into, bundled into one value.
 *
 * The loader and PluginContext take *this*, not a lengthening list of
 * arguments — every capability added before this (field types, head, events,
 * migrations) had grown both signatures and every test that built them. Adding
 * the next capability now means one field here, nothing else.
 *
 * Each registry defaults to a fresh instance, so a test — or a plugin's own
 * package-integration test — constructs only the ones it cares about by name:
 *
 *   new PluginCapabilities(fieldTypes: $registry)
 *
 * The kernel passes the instances it composed and shares.
 */
final class PluginCapabilities
{
    public function __construct(
        public readonly FieldTypeRegistry $fieldTypes = new FieldTypeRegistry(),
        public readonly HeadContributorRegistry $head = new HeadContributorRegistry(),
        public readonly EventDispatcher $events = new EventDispatcher(),
        public readonly MigrationRegistry $migrations = new MigrationRegistry(),
        public readonly AdminPageRegistry $adminPages = new AdminPageRegistry(),
        public readonly MaintenanceRegistry $maintenance = new MaintenanceRegistry(),
        // The live connection, for the storage capability. Null when a caller
        // has no database (a unit test, or a plugin that never touches storage).
        public readonly ?Connection $db = null,
    ) {
    }
}

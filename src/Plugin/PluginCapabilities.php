<?php

declare(strict_types=1);

namespace Nimbus\Plugin;

use Nimbus\Admin\AdminPageRegistry;
use Nimbus\Auth\CapabilityRegistry;
use Nimbus\Content\FieldTypeRegistry;
use Nimbus\Database\Connection;
use Nimbus\Database\MigrationRegistry;
use Nimbus\Http\PluginRouteRegistry;
use Nimbus\Mcp\Guide\SkillRegistry;
use Nimbus\Mcp\McpToolsetRegistry;
use Nimbus\Site\HeadContributorRegistry;
use Nimbus\Site\PageSectionRegistry;
use Nimbus\Site\ViewDataContributorRegistry;
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
        // Live body view-data plugins contribute to themed content pages (ADR 0027).
        public readonly ViewDataContributorRegistry $viewData = new ViewDataContributorRegistry(),
        public readonly EventDispatcher $events = new EventDispatcher(),
        public readonly MigrationRegistry $migrations = new MigrationRegistry(),
        public readonly AdminPageRegistry $adminPages = new AdminPageRegistry(),
        public readonly MaintenanceRegistry $maintenance = new MaintenanceRegistry(),
        // Agent-guidance fragments (ADR 0013): each becomes a plugin guide resource.
        public readonly SkillRegistry $skills = new SkillRegistry(),
        // Grantable, wildcard-immune management capabilities a plugin declares (ADR 0015).
        public readonly CapabilityRegistry $capabilities = new CapabilityRegistry(),
        // Plugin-registered MCP toolsets, composed after the core ones (ADR 0016).
        public readonly McpToolsetRegistry $mcpToolsets = new McpToolsetRegistry(),
        // Public routes plugins serve under /ext/{namespace} (ADR 0017).
        public readonly PluginRouteRegistry $routes = new PluginRouteRegistry(),
        // Typed service ports between plugins (ADR 0019).
        public readonly ServiceRegistry $services = new ServiceRegistry(),
        // Themed public pages plugins serve at a pretty handle (ADR 0023).
        public readonly PageSectionRegistry $pageSections = new PageSectionRegistry(),
        // The live connection, for the storage capability. Null when a caller
        // has no database (a unit test, or a plugin that never touches storage).
        public readonly ?Connection $db = null,
    ) {
    }
}

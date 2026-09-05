<?php

declare(strict_types=1);

namespace Nimbus\Plugin;

use Nimbus\Content\ContentReader;
use Nimbus\Content\FieldTypeRegistry;
use Nimbus\Database\Connection;

/**
 * Everything a plugin is allowed to touch.
 *
 * Nine capabilities today: field types, head contributions (ADR 0004), events
 * — subscribe, and emit under the plugin's own namespace (ADR 0014) — migrations
 * for the plugin's own tables, a grantable management capability (ADR 0015), an
 * MCP toolset (ADR 0016), public routes under /ext (ADR 0017), typed service ports
 * to other plugins (ADR 0019), storage of its own data
 * (ADR 0005), admin pages, maintenance tasks, and an agent-guidance skill
 * (ADR 0013). Each was added alongside a plugin that concretely needed it — field
 * types by the built-in types and Markdown, head contributions by plugin-seo,
 * events/migrations/storage/admin pages by plugin-analytics, maintenance by both
 * analytics and api-advanced (retention of their own tables), the skill by
 * Markdown (teaching agents its field type). Public routes and permissions get
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
 * - the **core** database connection, core tables, and repositories — direct
 *   access to core's data bypasses the services owning transactions, validation,
 *   slugs and events. (A plugin may own and query its *own* tables via storage(),
 *   ADR 0005 — that bypasses nothing.)
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
    private ViewDataRegistrar $viewData;
    private EventRegistrar $events;
    private MigrationRegistrar $migrations;
    private AdminPageRegistrar $adminPages;
    private MaintenanceRegistrar $maintenance;
    private SkillRegistrar $skills;
    private CapabilitiesRegistrar $capabilities;
    private McpRegistrar $mcp;
    private RouteRegistrar $routes;
    private ServiceRegistrar $services;
    private PageSectionRegistrar $pages;
    private ?Connection $db;
    private FieldTypeRegistry $contentTypes;
    private ?PluginStorage $storage = null;
    private ?ContentReader $content = null;

    public function __construct(PluginCapabilities $capabilities, private string $pluginId)
    {
        $this->fieldTypes   = new FieldTypeRegistrar($capabilities->fieldTypes, $pluginId);
        $this->head         = new HeadRegistrar($capabilities->head, $pluginId);
        $this->viewData     = new ViewDataRegistrar($capabilities->viewData, $pluginId);
        $this->events       = new EventRegistrar($capabilities->events, $pluginId);
        $this->migrations   = new MigrationRegistrar($capabilities->migrations, $pluginId);
        $this->adminPages   = new AdminPageRegistrar($capabilities->adminPages, $pluginId);
        $this->maintenance  = new MaintenanceRegistrar($capabilities->maintenance, $pluginId);
        $this->skills       = new SkillRegistrar($capabilities->skills, $pluginId);
        $this->capabilities = new CapabilitiesRegistrar($capabilities->capabilities, $pluginId);
        $this->mcp          = new McpRegistrar($capabilities->mcpToolsets, $pluginId);
        $this->routes       = new RouteRegistrar($capabilities->routes, $pluginId);
        $this->services     = new ServiceRegistrar($capabilities->services, $pluginId);
        $this->pages        = new PageSectionRegistrar($capabilities->pageSections, $pluginId);
        $this->db           = $capabilities->db;
        $this->contentTypes = $capabilities->fieldTypes;
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

    /** Register view-data contributors for themed content pages (ADR 0027). Stamped with this plugin's id. */
    public function viewData(): ViewDataRegistrar
    {
        return $this->viewData;
    }

    /** Subscribe to events, and emit under this plugin's own namespace (ADR 0014). Stamped with its id. */
    public function events(): EventRegistrar
    {
        return $this->events;
    }

    /** Declare migrations for the plugin's own tables. Stamped with its id. */
    public function migrations(): MigrationRegistrar
    {
        return $this->migrations;
    }

    /** Register admin pages (login-gated, in the admin shell). Stamped with its id. */
    public function adminPages(): AdminPageRegistrar
    {
        return $this->adminPages;
    }

    /** Register maintenance tasks run by `nimbus prune` (e.g. retention of the plugin's own tables). Stamped with its id. */
    public function maintenance(): MaintenanceRegistrar
    {
        return $this->maintenance;
    }

    /** Publish this plugin's agent guide (ADR 0013), served as `nimbus://guide/plugin/{id}`. Stamped with its id. */
    public function skills(): SkillRegistrar
    {
        return $this->skills;
    }

    /** Declare this plugin's grantable, wildcard-immune management capability (ADR 0015). Stamped with its id. */
    public function capabilities(): CapabilitiesRegistrar
    {
        return $this->capabilities;
    }

    /** Register this plugin's MCP toolset — agent-facing tools that gate on its capability (ADR 0016). Stamped with its id. */
    public function mcp(): McpRegistrar
    {
        return $this->mcp;
    }

    /** Register public routes under /ext/{namespace} — storefronts, webhooks (ADR 0017). Stamped with its id. */
    public function routes(): RouteRegistrar
    {
        return $this->routes;
    }

    /** Provide a typed service to, or consume one from, another plugin (ADR 0019). Stamped with its id. */
    public function services(): ServiceRegistrar
    {
        return $this->services;
    }

    /** Register a themed public page at a pretty handle — a storefront, a directory (ADR 0023). Stamped with its id. */
    public function pages(): PageSectionRegistrar
    {
        return $this->pages;
    }

    /**
     * Read and write the plugin's own tables (ADR 0005). Requires a database —
     * available in the running kernel; absent only where there is no connection.
     */
    public function storage(): PluginStorage
    {
        return $this->storage ??= new PluginStorage(
            $this->db ?? throw new \RuntimeException('The storage capability requires a database connection.'),
        );
    }

    /**
     * Read **published** collection entries in-process (ADR 0029) — the read-only
     * complement to storage(), for a plugin that composes with content it does not
     * own (an app reading its menu, its services). Exposes only what the public API
     * and themes already expose; it cannot write. Requires a database — available in
     * the running kernel; absent only where there is no connection.
     */
    public function content(): ContentReader
    {
        return $this->content ??= new ContentReader(
            $this->db ?? throw new \RuntimeException('The content-read capability requires a database connection.'),
            $this->contentTypes,
        );
    }

    /** The id this plugin was loaded under, from its Composer manifest. */
    public function pluginId(): string
    {
        return $this->pluginId;
    }
}

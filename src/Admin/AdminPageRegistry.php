<?php

declare(strict_types=1);

namespace Nimbus\Admin;

/**
 * Admin pages plugins register — a slug, a sidebar label + icon, and a handler
 * that renders the page. Composed once and shared, like the other capability
 * registries: what a plugin registers here must reach the admin routing and nav.
 *
 * Stamped with the registering plugin's id so a failing plugin's pages roll back
 * with its other registrations.
 */
final class AdminPageRegistry
{
    /** @var list<array{slug:string,label:string,icon:string,handler:callable,provider:string,capability:?string}> */
    private array $pages = [];

    /** @var list<array{page:string,action:string,handler:callable,provider:string}> POST form handlers (H3) */
    private array $actions = [];

    public function add(string $slug, string $label, string $icon, callable $handler, string $provider, ?string $capability = null): void
    {
        // First-wins-loudly (like DuplicateFieldType): a second page claiming a
        // slug already taken throws, and the loader turns that into REGISTER_FAILED
        // + full rollback — rather than silently registering an unreachable route
        // with a live nav entry (PLUG-4).
        foreach ($this->pages as $existing) {
            if ($existing['slug'] === $slug) {
                throw new \InvalidArgumentException("An admin page slug \"{$slug}\" is already registered by \"{$existing['provider']}\".");
            }
        }
        $this->pages[] = [
            'slug'       => $slug,
            'label'      => $label,
            'icon'       => $icon,
            'handler'    => $handler,
            'provider'   => $provider,
            'capability' => $capability,
        ];
    }

    /**
     * Register a POST action for a plugin's admin page (H3) — a form target at
     * `/admin/{page}/{action}`. Auth, CSRF and the page's capability are enforced
     * by core (PluginPagesController) before the handler runs, so a plugin can't
     * ship an unguarded admin write.
     */
    public function addAction(string $page, string $action, callable $handler, string $provider): void
    {
        foreach ($this->actions as $existing) {
            if ($existing['page'] === $page && $existing['action'] === $action) {
                throw new \InvalidArgumentException("Admin action \"{$page}/{$action}\" is already registered by \"{$existing['provider']}\".");
            }
        }
        $this->actions[] = ['page' => $page, 'action' => $action, 'handler' => $handler, 'provider' => $provider];
    }

    /** @return list<array{slug:string,label:string,icon:string,handler:callable,provider:string,capability:?string}> */
    public function all(): array
    {
        return $this->pages;
    }

    /** @return list<array{page:string,action:string,handler:callable,provider:string}> */
    public function actions(): array
    {
        return $this->actions;
    }

    /** Drop a provider's pages and actions — used when a plugin's load fails. */
    public function forgetProvider(string $provider): void
    {
        $this->pages = array_values(array_filter(
            $this->pages,
            static fn (array $page): bool => $page['provider'] !== $provider,
        ));
        $this->actions = array_values(array_filter(
            $this->actions,
            static fn (array $a): bool => $a['provider'] !== $provider,
        ));
    }
}

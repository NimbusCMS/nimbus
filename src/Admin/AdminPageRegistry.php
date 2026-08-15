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
    /** @var list<array{slug:string,label:string,icon:string,handler:callable,provider:string}> */
    private array $pages = [];

    public function add(string $slug, string $label, string $icon, callable $handler, string $provider): void
    {
        $this->pages[] = [
            'slug'     => $slug,
            'label'    => $label,
            'icon'     => $icon,
            'handler'  => $handler,
            'provider' => $provider,
        ];
    }

    /** @return list<array{slug:string,label:string,icon:string,handler:callable,provider:string}> */
    public function all(): array
    {
        return $this->pages;
    }

    /** Drop a provider's pages — used when a plugin's load fails. */
    public function forgetProvider(string $provider): void
    {
        $this->pages = array_values(array_filter(
            $this->pages,
            static fn (array $page): bool => $page['provider'] !== $provider,
        ));
    }
}

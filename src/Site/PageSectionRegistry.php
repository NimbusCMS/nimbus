<?php

declare(strict_types=1);

namespace Nimbus\Site;

/**
 * Holds the public **page sections** plugins register (ADR 0023) — a pretty
 * top-level handle (`shop`) bound to a resolver and the plugin's default-template
 * directory. Composed once by the kernel and shared, like {@see HeadContributorRegistry}:
 * whatever a plugin registers here must land in the instance {@see SiteController}
 * reads to mount the routes.
 *
 * A handle is claimed by exactly one plugin: a second registration of the same
 * handle throws, so the offending plugin fails its load and rolls back (parity
 * with the plugin-route namespace). Registrations are stamped with the plugin id
 * so a failed load's sections roll back via {@see forgetProvider}.
 */
final class PageSectionRegistry
{
    /** @var array<string,array{resolver:callable(\Nimbus\Http\Request):?PageView,templates:?string,provider:string}> */
    private array $sections = [];

    /**
     * @param callable(\Nimbus\Http\Request):?PageView $resolver
     * @param ?string $templatesPath the plugin's default-templates dir (theme overrides win), or null
     */
    public function add(string $handle, callable $resolver, ?string $templatesPath, string $provider): void
    {
        if (isset($this->sections[$handle])) {
            throw new \InvalidArgumentException("The page section \"{$handle}\" is already registered by another plugin.");
        }
        $this->sections[$handle] = ['resolver' => $resolver, 'templates' => $templatesPath, 'provider' => $provider];
    }

    public function has(string $handle): bool
    {
        return isset($this->sections[$handle]);
    }

    /**
     * @return array{resolver:callable(\Nimbus\Http\Request):?PageView,templates:?string,provider:string}|null
     */
    public function find(string $handle): ?array
    {
        return $this->sections[$handle] ?? null;
    }

    /** @return list<string> the registered handles, for route mounting */
    public function handles(): array
    {
        return array_keys($this->sections);
    }

    /** Remove everything a provider registered — used when a plugin load fails. */
    public function forgetProvider(string $provider): void
    {
        foreach ($this->sections as $handle => $section) {
            if ($section['provider'] === $provider) {
                unset($this->sections[$handle]);
            }
        }
    }
}

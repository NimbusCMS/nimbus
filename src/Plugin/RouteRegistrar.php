<?php

declare(strict_types=1);

namespace Nimbus\Plugin;

use Nimbus\Http\PluginRouteRegistry;
use Nimbus\Http\Request;
use Nimbus\Http\Response;

/**
 * The public-route capability, as a plugin sees it (ADR 0017, H4).
 *
 * A plugin serves routes under `/ext/{namespace}/…` — a Commerce storefront's
 * cart/checkout actions, a payment webhook. The registrar binds this plugin's
 * loader-verified id, so its namespace claim and routes roll back with it if its
 * load fails, and it cannot register under another plugin's namespace. Mirrors the
 * other registrars.
 *
 * These routes are **public**: outside the admin auth middleware, no automatic
 * CSRF. The plugin owns its authentication — verify a webhook signature over
 * {@see Request::rawBody()}; check a token for a privileged action; never trust an
 * ambient session cookie (it grants nothing here).
 */
final class RouteRegistrar
{
    public function __construct(
        private PluginRouteRegistry $registry,
        private string $pluginId,
    ) {
    }

    /** @param callable(Request,array<string,string>):Response $handler */
    public function get(string $namespace, string $path, callable $handler): void
    {
        $this->registry->add($this->pluginId, $namespace, 'GET', $path, $handler);
    }

    /** @param callable(Request,array<string,string>):Response $handler */
    public function post(string $namespace, string $path, callable $handler): void
    {
        $this->registry->add($this->pluginId, $namespace, 'POST', $path, $handler);
    }

    /** @param callable(Request,array<string,string>):Response $handler */
    public function put(string $namespace, string $path, callable $handler): void
    {
        $this->registry->add($this->pluginId, $namespace, 'PUT', $path, $handler);
    }

    /** @param callable(Request,array<string,string>):Response $handler */
    public function patch(string $namespace, string $path, callable $handler): void
    {
        $this->registry->add($this->pluginId, $namespace, 'PATCH', $path, $handler);
    }

    /** @param callable(Request,array<string,string>):Response $handler */
    public function delete(string $namespace, string $path, callable $handler): void
    {
        $this->registry->add($this->pluginId, $namespace, 'DELETE', $path, $handler);
    }
}

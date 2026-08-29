<?php

declare(strict_types=1);

namespace Nimbus\Http;

use InvalidArgumentException;

/**
 * The public routes plugins register (ADR 0017, H4). The plugin boundary
 * deliberately excluded routes until a real consumer arrived (a Commerce
 * storefront + payment webhooks); this is the smallest seam that opens it safely.
 *
 * Every plugin route lives under the reserved prefix **`/ext/{namespace}/…`**.
 * That is the load-bearing containment:
 *
 *  - it is registered by the kernel *after* the core controllers, so a plugin can
 *    never shadow `/admin` or `/api`, and *before* the content catch-all, so it
 *    resolves cleanly;
 *  - `ext` is a reserved collection handle, so no content ever lives under it; and
 *  - the namespace is unique across plugins (a second claim fails the load), so
 *    two plugins cannot collide inside the space either.
 *
 * The routes are **public** — outside the admin auth middleware. A plugin owns its
 * own authentication there: a webhook verifies a provider signature over
 * {@see Request::rawBody()}; a privileged action checks a token. An ambient admin
 * session grants nothing (parity with the bearer-only API).
 */
final class PluginRouteRegistry
{
    public const PREFIX = 'ext';

    private const METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];

    /** @var list<array{method:string,pattern:string,handler:callable,provider:string,namespace:string}> */
    private array $routes = [];

    /** @var array<string,string> namespace => the plugin that owns it */
    private array $owners = [];

    /**
     * Register a plugin route. `$path` is relative to the plugin's namespace, so
     * `('shop', 'POST', '/webhook', …)` serves `POST /ext/shop/webhook`.
     *
     * @param callable(Request,array<string,string>):Response $handler
     *
     * @throws InvalidArgumentException on a bad method/namespace/path, or a
     *                                  namespace another plugin already owns — each
     *                                  fails the registering plugin's load.
     */
    public function add(string $provider, string $namespace, string $method, string $path, callable $handler): void
    {
        $method = strtoupper($method);
        if (!in_array($method, self::METHODS, true)) {
            throw new InvalidArgumentException("Unsupported HTTP method \"{$method}\" for a plugin route.");
        }
        if (preg_match('/^[a-z][a-z0-9-]*$/', $namespace) !== 1) {
            throw new InvalidArgumentException("A plugin route namespace must be a lowercase URL token starting with a letter: \"{$namespace}\".");
        }
        if (($this->owners[$namespace] ?? $provider) !== $provider) {
            throw new InvalidArgumentException("Route namespace \"{$namespace}\" is already owned by {$this->owners[$namespace]}.");
        }
        // A path is a Router pattern under the namespace. Keep it to safe URL
        // characters plus `{param}` / `{param*}`, and forbid `..`; it can never
        // introduce a new top-level segment or escape the prefix.
        if ($path !== '' && (preg_match('#^/[A-Za-z0-9._~/{}*-]*$#', $path) !== 1 || str_contains($path, '..'))) {
            throw new InvalidArgumentException("Invalid plugin route path \"{$path}\".");
        }

        $this->owners[$namespace] = $provider;
        $suffix                   = $path === '' || $path === '/' ? '' : $path;
        $this->routes[]           = [
            'method'    => $method,
            'pattern'   => '/' . self::PREFIX . '/' . $namespace . $suffix,
            'handler'   => $handler,
            'provider'  => $provider,
            'namespace' => $namespace,
        ];
    }

    /**
     * Every registered route, in registration order — the kernel mounts these
     * between the core controllers and the content catch-all.
     *
     * @return list<array{method:string,pattern:string,handler:callable,provider:string,namespace:string}>
     */
    public function all(): array
    {
        return $this->routes;
    }

    /** Remove a provider's routes and namespace claim — used on plugin-load rollback. */
    public function forgetProvider(string $provider): void
    {
        $this->routes = array_values(array_filter(
            $this->routes,
            static fn (array $r): bool => $r['provider'] !== $provider,
        ));
        foreach ($this->owners as $namespace => $owner) {
            if ($owner === $provider) {
                unset($this->owners[$namespace]);
            }
        }
    }
}

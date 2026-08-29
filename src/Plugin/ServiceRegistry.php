<?php

declare(strict_types=1);

namespace Nimbus\Plugin;

use InvalidArgumentException;

/**
 * Typed service ports between plugins (ADR 0019, Hinge 5).
 *
 * Some plugins cooperate: a Commerce checkout must ask Inventory "reserve this,
 * is it available?" — a **synchronous** call with a result, which events (fire-and-
 * forget) can't do, and which the own-tables boundary (ADR 0005) forbids doing by
 * reaching into another plugin's tables. This is the one seam for it.
 *
 * It is deliberately **not** the generic service locator the principles reject. The
 * difference is the contract:
 *
 *  - a service is keyed by an **interface** — a published contract, in a package
 *    both plugins depend on — never an arbitrary string or a concrete class;
 *  - `get()` is generic on that contract (`class-string<T> → ?T`), so a consumer
 *    gets a **typed** object, not a bare `object` it has to trust;
 *  - it carries **plugin services only** — core internals are wired in Application,
 *    never fetched here;
 *  - **one provider per contract** (a second registration fails), so a plugin can't
 *    silently shadow another's port;
 *  - it is **fail-safe** — an unprovided contract returns null, so a consumer
 *    degrades (Commerce refuses checkout if no inventory is installed) rather than
 *    assuming a collaborator is present.
 *
 * Providers register at plugin load; consumers call `get()` at request time (never
 * during their own `register()`, when load order is undefined).
 */
final class ServiceRegistry
{
    /** @var array<class-string,array{impl:object,provider:string}> contract => implementation */
    private array $services = [];

    /**
     * Publish an implementation of a contract interface.
     *
     * @param class-string $contract the interface other plugins consume by
     *
     * @throws InvalidArgumentException if the contract is not an interface, the
     *   implementation does not implement it, or a provider already claimed it —
     *   each fails the registering plugin's load.
     */
    public function provide(string $contract, object $impl, string $provider): void
    {
        if (!interface_exists($contract)) {
            throw new InvalidArgumentException("A service contract must be an interface: \"{$contract}\".");
        }
        if (!$impl instanceof $contract) {
            throw new InvalidArgumentException(get_class($impl) . " does not implement the contract \"{$contract}\".");
        }
        if (isset($this->services[$contract])) {
            throw new InvalidArgumentException("A provider for \"{$contract}\" is already registered by {$this->services[$contract]['provider']}.");
        }
        $this->services[$contract] = ['impl' => $impl, 'provider' => $provider];
    }

    /**
     * The implementation of a contract, or null if no plugin provides it.
     *
     * @template T of object
     * @param class-string<T> $contract
     * @return T|null
     */
    public function get(string $contract): ?object
    {
        $impl = $this->services[$contract]['impl'] ?? null;
        /** @var T|null $impl — provide() guaranteed $impl instanceof $contract */
        return $impl;
    }

    /** Remove a provider's services — used on plugin-load rollback. */
    public function forgetProvider(string $provider): void
    {
        $this->services = array_filter(
            $this->services,
            static fn (array $e): bool => $e['provider'] !== $provider,
        );
    }
}

<?php

declare(strict_types=1);

namespace Nimbus\Plugin;

/**
 * The service-port capability, as a plugin sees it (ADR 0019, Hinge 5).
 *
 * A plugin **provides** a typed implementation of a contract interface (bound to
 * this plugin's id, so it rolls back on a failed load and can't be spoofed), and
 * **consumes** another plugin's by requesting the contract type. Consuming is
 * unrestricted — a published contract is meant to be used — and fail-safe: an
 * absent contract returns null, so a plugin depends *softly* on its collaborators.
 *
 * Call {@see get()} at request time (in a handler or tool), never inside
 * `register()` — providers load in an undefined order.
 */
final class ServiceRegistrar
{
    public function __construct(
        private ServiceRegistry $registry,
        private string $pluginId,
    ) {
    }

    /**
     * Publish this plugin's implementation of a contract interface.
     *
     * @param class-string $contract
     *
     * @throws \InvalidArgumentException on a non-interface contract, a mismatched
     *   implementation, or a contract another plugin already provides.
     */
    public function provide(string $contract, object $impl): void
    {
        $this->registry->provide($contract, $impl, $this->pluginId);
    }

    /**
     * Obtain the implementation of a contract another plugin published, or null.
     *
     * @template T of object
     * @param class-string<T> $contract
     * @return T|null
     */
    public function get(string $contract): ?object
    {
        return $this->registry->get($contract);
    }
}

<?php

declare(strict_types=1);

namespace Nimbus\Site;

/**
 * Holds the head contributors plugins register, and renders their combined
 * output for a page. Composed once by the kernel and shared, like the field-type
 * registry: whatever a plugin registers here must land in the instance the site
 * renderer reads.
 *
 * Contributions are stamped with the registering plugin's id so a failing
 * plugin's contributors roll back with its field types (forgetProvider).
 */
final class HeadContributorRegistry
{
    /** @var list<array{contributor:HeadContributor,provider:string}> */
    private array $contributors = [];

    public function add(HeadContributor $contributor, string $provider): void
    {
        $this->contributors[] = ['contributor' => $contributor, 'provider' => $provider];
    }

    /** Remove everything a provider registered — used when a plugin load fails. */
    public function forgetProvider(string $provider): void
    {
        $this->contributors = array_values(array_filter(
            $this->contributors,
            static fn (array $entry): bool => $entry['provider'] !== $provider,
        ));
    }

    /**
     * The combined <head> HTML for a page, in registration order.
     *
     * Each contributor is isolated: one that throws is logged and skipped, never
     * allowed to turn a live public page into a 500. This is the deliberate
     * difference from the (loud, propagating) event contract — see ADR 0004.
     */
    public function render(PageContext $page): string
    {
        $html = '';
        foreach ($this->contributors as $entry) {
            try {
                $html .= $entry['contributor']->head($page);
            } catch (\Throwable $e) {
                error_log("[nimbus head] {$entry['provider']}: " . $e);
            }
        }
        return $html;
    }
}

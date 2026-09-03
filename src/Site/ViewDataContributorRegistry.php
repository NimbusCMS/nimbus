<?php

declare(strict_types=1);

namespace Nimbus\Site;

/**
 * Holds the view-data contributors plugins register, and collects their combined
 * data for a page. Composed once by the kernel and shared, like the
 * {@see HeadContributorRegistry} it mirrors (ADR 0027).
 *
 * Contributions are stamped with the registering plugin's id so a failing plugin's
 * contributors roll back with its field types (forgetProvider).
 */
final class ViewDataContributorRegistry
{
    /** @var list<array{contributor:ViewDataContributor,provider:string}> */
    private array $contributors = [];

    public function add(ViewDataContributor $contributor, string $provider): void
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
     * The combined view data for a page, keyed by contributing plugin id:
     * `['nimbuscms.storefront' => ['featured' => [...]]]`. A theme reads its
     * plugin's namespace defensively (`$contrib['pid']['key'] ?? default`).
     *
     * Each contributor is isolated: one that throws is logged and skipped, never
     * allowed to turn a live public page into a 500 (the ADR-0004 contract). Data
     * is namespaced under the provider id, so a contributor can never reach or
     * overwrite a core template variable, nor another plugin's data. Two
     * contributors from the same provider merge within that one namespace.
     *
     * @return array<string,array<string,mixed>>
     */
    public function collect(PageContext $page): array
    {
        $data = [];
        foreach ($this->contributors as $entry) {
            $provider = $entry['provider'];
            try {
                $contributed = $entry['contributor']->data($page);
            } catch (\Throwable $e) {
                error_log("[nimbus view-data] {$provider}: " . $e);
                continue;
            }
            $data[$provider] = array_merge($data[$provider] ?? [], $contributed);
        }
        return $data;
    }
}

<?php

declare(strict_types=1);

namespace Nimbus\Mcp\Guide;

/**
 * The agent-guidance fragments plugins declare (ADR 0013), collected at boot and
 * composed once — the eighth plugin capability. Each fragment becomes a
 * read-on-demand MCP resource `nimbus://guide/plugin/{id}`, so enabling a plugin
 * teaches agents how to drive it.
 *
 * **Static content only.** A fragment is authored text describing a plugin's
 * tools; it is never executed. It is surfaced to a tool-calling agent as
 * *resources only* — never the always-in-context `initialize.instructions`
 * (ADR 0013 §3) — and {@see GuideLibrary} wraps it in an untrusted-data envelope
 * on read. A fragment is **world-readable to any valid token**, so it must
 * contain no secret or per-tenant data.
 *
 * One fragment per plugin (keyed by provider id, which the loader has already
 * validated and which cannot be `core` or the reserved `nb` namespace), so a
 * plugin can neither mint a second guide URI nor collide with `nimbus://guide/core`.
 */
final class SkillRegistry
{
    /** @var array<string,array{title:string,body:string}> provider id => fragment */
    private array $fragments = [];

    public function add(string $provider, string $title, string $body): void
    {
        // Last registration wins for a provider — a plugin has exactly one guide.
        $this->fragments[$provider] = ['title' => $title, 'body' => $body];
    }

    /**
     * The plugin fragments as guide documents, in registration order. Each is
     * owner-stamped so {@see GuideLibrary} reads it back with attribution.
     *
     * @return list<GuideDocument>
     */
    public function documents(): array
    {
        $documents = [];
        foreach ($this->fragments as $provider => $fragment) {
            $documents[] = new GuideDocument(
                uri: 'nimbus://guide/plugin/' . $provider,
                name: 'nimbus-plugin-' . $provider . '-guide',
                title: $fragment['title'],
                description: 'Agent guide for the "' . $provider . '" plugin.',
                body: $fragment['body'],
                owner: $provider,
            );
        }
        return $documents;
    }

    /** Drop a provider's fragment — used when a plugin's load fails. */
    public function forgetProvider(string $provider): void
    {
        unset($this->fragments[$provider]);
    }
}

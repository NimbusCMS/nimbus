<?php

declare(strict_types=1);

namespace Nimbus\Mcp\Guide;

/**
 * One agent-guidance document, addressable as an MCP resource (ADR 0013).
 *
 * A document is **static content** — authored markdown for the core guide, or a
 * plugin-registered fragment (Slice 2). Its {@see $uri} is a fixed key in the
 * {@see GuideLibrary} registry, never a filesystem path: `resources/read` looks
 * the URI up here, so there is no path to traverse.
 *
 * `$owner` names where the text came from — `null` for core-authored content, or
 * a plugin id for a plugin fragment. The library uses it to wrap plugin text in
 * an untrusted-data envelope on read (a plugin's guide is reference documentation,
 * trusted exactly as much as the plugin's code — not instructions to the agent).
 */
final class GuideDocument
{
    public function __construct(
        public readonly string $uri,
        public readonly string $name,
        public readonly string $title,
        public readonly string $description,
        public readonly string $body,
        public readonly ?string $owner = null,
    ) {
    }

    /** The MCP resource descriptor for `resources/list` (no body). */
    /** @return array<string,string> */
    public function descriptor(): array
    {
        return [
            'uri'         => $this->uri,
            'name'        => $this->name,
            'title'       => $this->title,
            'description' => $this->description,
            'mimeType'    => 'text/markdown',
        ];
    }
}

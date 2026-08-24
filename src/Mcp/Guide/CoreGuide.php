<?php

declare(strict_types=1);

namespace Nimbus\Mcp\Guide;

/**
 * Loads the core-authored agent guidance from the repo (ADR 0013).
 *
 * The source is static markdown shipped with the CMS under `docs/agent/`, read by
 * **fixed, internal paths** — never from anything a request carries — so there is
 * no file-read primitive here. `instructions.md` is the tight always-in-context
 * brief; `core.md` is the full `nimbus://guide/core` reference. The factory
 * composes these with any plugin fragments (Slice 2) into a {@see GuideLibrary}.
 */
final class CoreGuide
{
    public const CORE_URI = 'nimbus://guide/core';

    private const INSTRUCTIONS_FILE = 'docs/agent/instructions.md';
    private const CORE_FILE         = 'docs/agent/core.md';

    /** A defensive ceiling on the always-in-context brief (ADR 0013 keeps it tight). */
    public const INSTRUCTIONS_MAX_BYTES = 8192;

    /** The tight `initialize.instructions` brief. */
    public static function instructions(string $basePath): string
    {
        return self::read($basePath, self::INSTRUCTIONS_FILE);
    }

    /** The full core guide, as the `nimbus://guide/core` resource document. */
    public static function document(string $basePath): GuideDocument
    {
        return new GuideDocument(
            uri: self::CORE_URI,
            name: 'nimbus-core-guide',
            title: 'NimbusCMS — operating guide for agents',
            description: 'How to drive NimbusCMS over MCP: collections, entries, media, users, tokens, settings, and the cross-cutting rules.',
            body: self::read($basePath, self::CORE_FILE),
        );
    }

    private static function read(string $basePath, string $relative): string
    {
        $path = rtrim($basePath, '/') . '/' . $relative;
        $body = is_file($path) ? file_get_contents($path) : false;
        // A missing guide file is a packaging error, not a runtime one: degrade to
        // empty rather than fail the whole MCP handshake. (CI ships the files, and
        // the coverage drift test guards their content.)
        return $body === false ? '' : $body;
    }
}

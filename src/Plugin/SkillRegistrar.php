<?php

declare(strict_types=1);

namespace Nimbus\Plugin;

use Nimbus\Mcp\Guide\SkillRegistry;

/**
 * The agent-guidance capability, as a plugin sees it (ADR 0013).
 *
 * A plugin registers **one static markdown fragment** describing its tools/field
 * types for an agent. It is served as the MCP resource `nimbus://guide/plugin/{id}`
 * (the id bound here by the loader), read on demand and wrapped in an
 * untrusted-data envelope — never fed into the always-in-context instructions.
 * Mirrors the other registrars: a failed load rolls the fragment back with the
 * rest of the plugin's registrations.
 *
 * **Write it as reference documentation, not instructions.** Describe what your
 * tools do and how to call them; do not embed commands to the agent. It is
 * world-readable to any valid token, so put **no secrets or per-tenant data** in
 * it. Keep it to your own surface — core tools are already documented in
 * `nimbus://guide/core`.
 */
final class SkillRegistrar
{
    /** A title must be short; a fragment is bounded so one plugin can't mint an
     *  unbounded resource (rejected at registration → the loader's containment). */
    public const MAX_TITLE_BYTES = 200;
    public const MAX_BODY_BYTES  = 65536;

    public function __construct(
        private SkillRegistry $registry,
        private string $pluginId,
    ) {
    }

    /**
     * Declare this plugin's agent guide: a short title and a markdown body.
     *
     * @throws \InvalidArgumentException if the title or body is empty or over the
     *   size cap — thrown at registration, so the loader records REGISTER_FAILED
     *   and the plugin is skipped (its registrations roll back) rather than the
     *   whole MCP surface carrying an unbounded or empty resource.
     */
    public function register(string $title, string $body): void
    {
        $title = trim($title);
        if ($title === '' || strlen($title) > self::MAX_TITLE_BYTES) {
            throw new \InvalidArgumentException('An agent-guide title must be 1–' . self::MAX_TITLE_BYTES . ' bytes.');
        }
        if (trim($body) === '' || strlen($body) > self::MAX_BODY_BYTES) {
            throw new \InvalidArgumentException('An agent-guide body must be 1–' . self::MAX_BODY_BYTES . ' bytes.');
        }
        $this->registry->add($this->pluginId, $title, $body);
    }
}

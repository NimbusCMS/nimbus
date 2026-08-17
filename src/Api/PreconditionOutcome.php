<?php

declare(strict_types=1);

namespace Nimbus\Api;

/**
 * The result of checking a write's optimistic-concurrency precondition against
 * an entry's current version (ADR 0007). One enum for both transports, so HTTP
 * and MCP map identical outcomes to their own wire codes (428/412 vs JSON-RPC
 * errors) without either owning the check.
 */
enum PreconditionOutcome
{
    /** No precondition was presented; the write must not proceed. */
    case Required;
    /** A precondition was presented but the entry has since changed. */
    case Failed;
    /** The presented precondition matches the current version; proceed. */
    case Satisfied;
}

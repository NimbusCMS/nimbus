<?php

declare(strict_types=1);

namespace Nimbus\Content;

use RuntimeException;

/**
 * Thrown when an optimistic-concurrency write loses the compare-and-swap: the
 * entry's version changed between the caller's read and this write, so the
 * expected version no longer matches (ADR 0007). It deliberately does NOT extend
 * PDOException, so it sails past {@see EntryService}'s duplicate-key catch and
 * rides {@see \Nimbus\Database\Connection::transaction}'s rollback; the API/MCP
 * write layer ({@see \Nimbus\Api\EntryOperations}) catches it and maps it to a
 * 412 / precondition-failed. The admin path never triggers it (it passes no
 * expected version — last-write-wins).
 */
final class EntryConcurrencyConflict extends RuntimeException
{
}

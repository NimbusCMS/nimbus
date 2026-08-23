<?php

declare(strict_types=1);

namespace Nimbus\Database;

/**
 * The outcome of a migration run: what applied, and which **plugin** migrations
 * failed (a core failure throws {@see MigrationFailed} instead — see below). One
 * bad plugin migration no longer wedges the whole install (PLUG-1): its provider
 * is isolated and the rest continue, with the failure recorded here for the
 * operator.
 *
 * SECURITY: `failures[].error` carries the raw database error, which can include
 * SQL fragments, table/column names, even row values. It is for the **operator**
 * — CLI stderr / logs — only. Never render it into a web response or an MCP tool
 * result without treating it as untrusted.
 */
final readonly class MigrationReport
{
    /**
     * @param list<string> $applied migration names applied this run
     * @param list<array{provider:string,migration:string,error:string}> $failures plugin migrations that failed
     */
    public function __construct(
        public array $applied,
        public array $failures,
    ) {
    }

    public function ok(): bool
    {
        return $this->failures === [];
    }
}

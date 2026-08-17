<?php

declare(strict_types=1);

namespace Nimbus\Api;

/**
 * The audit context a content write happened in, supplied by the transport.
 *
 * EntryOperations emits the same who-did-what events (ADR 0007) regardless of
 * how a request arrived, but the *where* differs: an HTTP request has a client
 * IP and a path, while a local stdio MCP session has neither in the usual sense.
 * The transport fills these so the audit trail names the surface an agent used.
 */
final readonly class EntryOpContext
{
    public function __construct(
        public string $ip,
        public string $path,
    ) {
    }
}

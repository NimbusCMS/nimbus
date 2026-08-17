<?php

declare(strict_types=1);

namespace Nimbus\Api;

/**
 * The outcome of a shared content operation (EntryOperations), independent of
 * transport. HTTP maps these to status codes and MCP to JSON-RPC results/errors
 * — but the decision of *which* outcome occurred is made once, in the service.
 */
enum EntryOpStatus
{
    case Ok;
    /** The token's scopes do not permit this action on this resource. */
    case Forbidden;
    /** No such collection, or no such entry. */
    case NotFound;
    /** The submitted values failed validation. */
    case Invalid;
    /** A write arrived without the required concurrency precondition. */
    case PreconditionRequired;
    /** The concurrency precondition no longer matches the current entry. */
    case PreconditionFailed;
}

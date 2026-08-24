<?php

declare(strict_types=1);

namespace Nimbus\Content;

use RuntimeException;

/**
 * Raised when a collection cannot be deleted because a relation field in
 * ANOTHER collection still targets it (FU-14) — the reverse of the write-time
 * target validation ({@see CollectionsController::validateDraft} / ADMIN-14a).
 *
 * Deleting it would leave those fields pointing at nothing: they read as `[]`
 * (fail-closed by the DATA-1 guards) but then block the *other* collection's
 * next edit with a mystery "target does not exist" error, far from the cause.
 * Refusing — rather than nulling/re-pointing the sibling field, which would
 * silently mutate its schema and bump its version — is the reversible, no-
 * surprise choice, mirroring {@see \Nimbus\Media\MediaInUse}. Both surfaces
 * catch it: the admin renders it (escaped) server-side, MCP returns an `in_use`
 * error. The operator removes/retargets the field, then deletes.
 */
final class CollectionInUse extends RuntimeException
{
    /**
     * @param list<array{collection:string,collection_name:string,field_handle:string,field_label:string}> $usage
     */
    public function __construct(public readonly string $handle, public readonly array $usage)
    {
        $count = count($usage);
        $where = implode(', ', array_map(
            static fn (array $u): string => "“{$u['field_label']}” in “{$u['collection_name']}”",
            $usage,
        ));
        parent::__construct(
            "Can’t delete “{$handle}” — {$count} relation field" . ($count === 1 ? '' : 's')
            . ' still target' . ($count === 1 ? 's' : '') . " it: {$where}. Remove or retarget "
            . ($count === 1 ? 'it' : 'them') . ' first.',
        );
    }
}

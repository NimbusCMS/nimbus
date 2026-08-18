<?php

declare(strict_types=1);

namespace Nimbus\Media;

use RuntimeException;

/**
 * Raised when a media item cannot be deleted because content still references
 * it. It carries the usage so every caller — the admin, the API, MCP — can
 * pinpoint *where* and tell the user what to detach first, rather than silently
 * orphaning an image.
 */
final class MediaInUse extends RuntimeException
{
    /**
     * @param list<array{collection:string,entry_id:int,entry_title:string,entry_slug:string,field_handle:string,field_label:string}> $usage
     */
    public function __construct(
        public readonly int $mediaId,
        public readonly array $usage,
    ) {
        $count = count($usage);
        parent::__construct("This file is used by {$count} entr" . ($count === 1 ? 'y' : 'ies') . '; remove those references before deleting it.');
    }
}

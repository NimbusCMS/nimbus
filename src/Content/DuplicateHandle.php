<?php

declare(strict_types=1);

namespace Nimbus\Content;

use PDOException;
use RuntimeException;

/**
 * A collection handle is already taken.
 *
 * Raised from the unique index rather than a read-then-write check, so it is
 * correct under concurrency: two simultaneous creates cannot both pass. The
 * controller turns it into a field error instead of dropping the submission.
 */
final class DuplicateHandle extends RuntimeException
{
    public function __construct(public readonly string $handle, ?PDOException $previous = null)
    {
        parent::__construct("Collection handle already in use: {$handle}", 0, $previous);
    }
}

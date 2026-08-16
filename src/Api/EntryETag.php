<?php

declare(strict_types=1);

namespace Nimbus\Api;

/**
 * The API's optimistic-concurrency token for an entry (ADR 0007).
 *
 * A strong ETag derived from the entry id and its monotonic version, so any
 * change bumps it. A read returns it as `ETag`; a write presents it as
 * `If-Match` and only proceeds if it still matches — otherwise the client is
 * working from a stale copy. Kept in one place so the read that emits it and the
 * write that checks it can never disagree on the format.
 */
final class EntryETag
{
    /** The strong ETag for an entry at a given version, e.g. `"42-3"`. */
    public static function of(int $entryId, int $version): string
    {
        return '"' . $entryId . '-' . $version . '"';
    }

    /**
     * Does an `If-Match` header satisfy the current ETag? `*` matches any
     * existing entity; otherwise the current ETag must appear in the
     * comma-separated list (strong comparison — weak `W/"…"` validators never
     * match). A missing or empty header returns false; the caller decides
     * whether that is a 428 Precondition Required.
     */
    public static function ifMatchSatisfied(?string $ifMatch, string $current): bool
    {
        if ($ifMatch === null) {
            return false;
        }
        $ifMatch = trim($ifMatch);
        if ($ifMatch === '') {
            return false;
        }
        if ($ifMatch === '*') {
            return true;
        }

        foreach (explode(',', $ifMatch) as $candidate) {
            if (trim($candidate) === $current) {
                return true;
            }
        }

        return false;
    }
}

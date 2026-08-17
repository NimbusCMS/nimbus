<?php

declare(strict_types=1);

namespace Nimbus\Api;

/**
 * A write's read-before-write proof, in whichever form the transport speaks.
 *
 * The invariant is one (ADR 0007, ADR 0009): a write must show it read the
 * entry's current version, so two machine clients cannot silently overwrite
 * each other. The *encoding* differs — HTTP carries a strong `If-Match` ETag,
 * MCP carries a plain `version` integer — so each transport constructs the
 * matching variant and both evaluate through the same path here. HTTP keeps its
 * full ETag grammar (`*`, comma lists, weak-validator rejection) untouched.
 */
final readonly class Precondition
{
    private function __construct(
        private ?string $ifMatch,
        private ?int $version,
        private bool $byVersion,
    ) {
    }

    /** HTTP: the request's raw `If-Match` header (null when absent). */
    public static function ifMatch(?string $header): self
    {
        return new self($header, null, false);
    }

    /** MCP: the `version` the client read (null when the caller omitted it). */
    public static function version(?int $version): self
    {
        return new self(null, $version, true);
    }

    /** Check this precondition against an entry's current id and version. */
    public function evaluate(int $entryId, int $currentVersion): PreconditionOutcome
    {
        if ($this->byVersion) {
            if ($this->version === null) {
                return PreconditionOutcome::Required;
            }
            return $this->version === $currentVersion
                ? PreconditionOutcome::Satisfied
                : PreconditionOutcome::Failed;
        }

        if ($this->ifMatch === null || trim($this->ifMatch) === '') {
            return PreconditionOutcome::Required;
        }
        return EntryETag::ifMatchSatisfied($this->ifMatch, EntryETag::of($entryId, $currentVersion))
            ? PreconditionOutcome::Satisfied
            : PreconditionOutcome::Failed;
    }

    /** The client-facing reason a precondition was required, phrased for this transport. */
    public function requiredMessage(): string
    {
        return $this->byVersion
            ? "This write needs the entry's current version; read it first, then pass version."
            : "This write needs an If-Match header carrying the entry's current ETag.";
    }

    /** The client-facing reason a precondition failed, phrased for this transport. */
    public function failedMessage(): string
    {
        return $this->byVersion
            ? 'The entry changed since you last read it; re-read it and retry with the new version.'
            : 'The entry changed since you last read it; re-read it and retry.';
    }
}

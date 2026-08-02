<?php

declare(strict_types=1);

namespace Nimbus\Content;

use DateTimeImmutable;

/**
 * The publication state of an entry, derived from its stored status and
 * published_at. See docs/adr/0002-publication-lifecycle.md.
 *
 * There are three *stored* statuses (draft, published, archived) and four
 * *shown* states — "Scheduled" is a published entry whose published_at is still
 * in the future. Liveness is computed here and nowhere else, so the API, the
 * admin badges and any future public site all agree on what "public" means.
 */
final class Publication
{
    // Stored statuses — the whole vocabulary on nb_entries.status.
    public const DRAFT     = 'draft';
    public const PUBLISHED = 'published';
    public const ARCHIVED  = 'archived';

    // Derived states shown to people. SCHEDULED has no stored status of its own.
    public const STATE_DRAFT     = 'draft';
    public const STATE_SCHEDULED = 'scheduled';
    public const STATE_PUBLISHED = 'published';
    public const STATE_ARCHIVED  = 'archived';

    /** @return string[] the statuses a write may set */
    public static function storedStatuses(): array
    {
        return [self::DRAFT, self::PUBLISHED, self::ARCHIVED];
    }

    public static function isStoredStatus(string $status): bool
    {
        return in_array($status, self::storedStatuses(), true);
    }

    /**
     * The one definition of "public right now": published, with a publish time
     * that has arrived. A draft, an archived entry, or a scheduled entry whose
     * time has not come are all not live.
     */
    public static function isLive(string $status, ?string $publishedAt, ?DateTimeImmutable $now = null): bool
    {
        if ($status !== self::PUBLISHED || $publishedAt === null || $publishedAt === '') {
            return false;
        }
        $now = $now ?? new DateTimeImmutable();
        return new DateTimeImmutable($publishedAt) <= $now;
    }

    /** The state to show a person: draft / scheduled / published / archived. */
    public static function state(string $status, ?string $publishedAt, ?DateTimeImmutable $now = null): string
    {
        return match ($status) {
            self::ARCHIVED  => self::STATE_ARCHIVED,
            self::PUBLISHED => self::isLive($status, $publishedAt, $now) ? self::STATE_PUBLISHED : self::STATE_SCHEDULED,
            default         => self::STATE_DRAFT,
        };
    }

    public static function label(string $state): string
    {
        return match ($state) {
            self::STATE_SCHEDULED => 'Scheduled',
            self::STATE_PUBLISHED => 'Published',
            self::STATE_ARCHIVED  => 'Archived',
            default               => 'Draft',
        };
    }

    /**
     * Resolve the published_at to store for a save.
     *
     * Publishing with no explicit time goes live now; with an explicit time it
     * is honoured (future = scheduled, past = back-dated). Draft and archived
     * keep whatever timestamp was there, since it no longer affects liveness
     * and lets a later re-publish reuse the original date.
     */
    public static function resolvePublishedAt(string $status, ?string $requested, ?string $current, ?DateTimeImmutable $now = null): ?string
    {
        if ($status !== self::PUBLISHED) {
            return $current;
        }
        if ($requested !== null && $requested !== '') {
            return (new DateTimeImmutable($requested))->format('Y-m-d H:i:s');
        }
        if ($current !== null && $current !== '') {
            return $current;
        }
        return ($now ?? new DateTimeImmutable())->format('Y-m-d H:i:s');
    }
}

<?php

declare(strict_types=1);

namespace Nimbus\Tests\Unit;

use DateTimeImmutable;
use Nimbus\Content\Publication;
use PHPUnit\Framework\TestCase;

/**
 * The single definition of "public", and the derived state a person sees.
 * See docs/adr/0002-publication-lifecycle.md.
 */
final class PublicationTest extends TestCase
{
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new DateTimeImmutable('2026-08-02 12:00:00');
    }

    private function ago(string $spec): string
    {
        return $this->now->modify('-' . $spec)->format('Y-m-d H:i:s');
    }

    private function ahead(string $spec): string
    {
        return $this->now->modify('+' . $spec)->format('Y-m-d H:i:s');
    }

    // ------------------------------------------------------------- liveness

    public function test_only_published_with_an_arrived_time_is_live(): void
    {
        self::assertTrue(Publication::isLive('published', $this->ago('1 hour'), $this->now));
        self::assertTrue(Publication::isLive('published', $this->now->format('Y-m-d H:i:s'), $this->now), 'exactly now counts');
    }

    public function test_a_future_publish_time_is_not_live(): void
    {
        self::assertFalse(Publication::isLive('published', $this->ahead('1 hour'), $this->now));
    }

    public function test_draft_and_archived_are_never_live(): void
    {
        self::assertFalse(Publication::isLive('draft', $this->ago('1 day'), $this->now));
        self::assertFalse(Publication::isLive('archived', $this->ago('1 day'), $this->now));
    }

    public function test_published_without_a_time_is_not_live(): void
    {
        self::assertFalse(Publication::isLive('published', null, $this->now));
        self::assertFalse(Publication::isLive('published', '', $this->now));
    }

    // -------------------------------------------------------- derived state

    public function test_state_is_derived_from_status_and_time(): void
    {
        self::assertSame(Publication::STATE_DRAFT, Publication::state('draft', null, $this->now));
        self::assertSame(Publication::STATE_ARCHIVED, Publication::state('archived', $this->ago('1 day'), $this->now));
        self::assertSame(Publication::STATE_PUBLISHED, Publication::state('published', $this->ago('1 min'), $this->now));
        self::assertSame(Publication::STATE_SCHEDULED, Publication::state('published', $this->ahead('1 day'), $this->now));
    }

    public function test_scheduled_becomes_published_when_its_time_arrives(): void
    {
        $at = $this->ahead('2 hours');

        self::assertSame(Publication::STATE_SCHEDULED, Publication::state('published', $at, $this->now));
        // Same row, same stored status — only the clock moved forward.
        $later = $this->now->modify('+3 hours');
        self::assertSame(Publication::STATE_PUBLISHED, Publication::state('published', $at, $later));
        self::assertTrue(Publication::isLive('published', $at, $later), 'no status flip needed — liveness is a query');
    }

    public function test_labels(): void
    {
        self::assertSame('Scheduled', Publication::label(Publication::STATE_SCHEDULED));
        self::assertSame('Published', Publication::label(Publication::STATE_PUBLISHED));
        self::assertSame('Archived', Publication::label(Publication::STATE_ARCHIVED));
        self::assertSame('Draft', Publication::label(Publication::STATE_DRAFT));
    }

    // ---------------------------------------------------- stored vocabulary

    public function test_only_three_statuses_are_storable(): void
    {
        self::assertSame(['draft', 'published', 'archived'], Publication::storedStatuses());
        self::assertTrue(Publication::isStoredStatus('published'));
        self::assertFalse(Publication::isStoredStatus('scheduled'), 'scheduled is derived, never stored');
        self::assertFalse(Publication::isStoredStatus('nonsense'));
    }

    // --------------------------------------------------- resolving the time

    public function test_publishing_without_a_time_goes_live_now(): void
    {
        $resolved = Publication::resolvePublishedAt('published', null, null, $this->now);

        self::assertSame($this->now->format('Y-m-d H:i:s'), $resolved);
    }

    public function test_publishing_with_a_future_time_schedules(): void
    {
        $at       = $this->ahead('1 day');
        $resolved = Publication::resolvePublishedAt('published', $at, null, $this->now);

        self::assertSame($at, $resolved);
        self::assertFalse(Publication::isLive('published', $resolved, $this->now));
    }

    public function test_republishing_keeps_the_original_time_when_none_is_given(): void
    {
        $original = $this->ago('5 days');
        $resolved = Publication::resolvePublishedAt('published', null, $original, $this->now);

        self::assertSame($original, $resolved, 'an existing publish date is not reset to now');
    }

    public function test_draft_and_archived_keep_the_existing_time(): void
    {
        $existing = $this->ago('2 days');

        self::assertSame($existing, Publication::resolvePublishedAt('draft', null, $existing, $this->now));
        self::assertSame($existing, Publication::resolvePublishedAt('archived', null, $existing, $this->now));
        // Unpublishing keeps the timestamp so a later re-publish can reuse it,
        // but the entry is not live because the status is no longer published.
        self::assertFalse(Publication::isLive('draft', $existing, $this->now));
    }

    public function test_a_datetime_local_value_is_normalised(): void
    {
        $resolved = Publication::resolvePublishedAt('published', '2026-08-05T09:30', null, $this->now);

        self::assertSame('2026-08-05 09:30:00', $resolved);
    }
}

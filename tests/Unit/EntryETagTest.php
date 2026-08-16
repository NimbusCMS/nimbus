<?php

declare(strict_types=1);

namespace Nimbus\Tests\Unit;

use Nimbus\Api\EntryETag;
use PHPUnit\Framework\TestCase;

final class EntryETagTest extends TestCase
{
    public function test_it_builds_a_strong_quoted_etag(): void
    {
        self::assertSame('"42-3"', EntryETag::of(42, 3));
    }

    public function test_if_match_accepts_the_current_etag_a_list_containing_it_and_a_wildcard(): void
    {
        $current = EntryETag::of(42, 3);

        self::assertTrue(EntryETag::ifMatchSatisfied('"42-3"', $current));
        self::assertTrue(EntryETag::ifMatchSatisfied('"1-1", "42-3"', $current), 'any entry in the list matches');
        self::assertTrue(EntryETag::ifMatchSatisfied('*', $current), 'a wildcard matches any existing entity');
    }

    public function test_if_match_rejects_a_stale_missing_empty_or_weak_validator(): void
    {
        $current = EntryETag::of(42, 3);

        self::assertFalse(EntryETag::ifMatchSatisfied('"42-2"', $current), 'a stale version does not match');
        self::assertFalse(EntryETag::ifMatchSatisfied(null, $current), 'a missing header does not match');
        self::assertFalse(EntryETag::ifMatchSatisfied('', $current));
        self::assertFalse(EntryETag::ifMatchSatisfied('W/"42-3"', $current), 'a weak validator never matches for If-Match');
    }
}

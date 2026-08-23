<?php

declare(strict_types=1);

namespace Nimbus\Tests\Unit;

use Nimbus\Auth\Authorizer;
use PHPUnit\Framework\TestCase;

/**
 * `Authorizer::holds` — the one grant-side predicate behind every subset-only
 * check (admin role granting, MCP token/user tools). The load-bearing property:
 * it delegates to `can`, so it inherits management-immunity — a content wildcard
 * never "holds" a management capability.
 */
final class AuthorizerHoldsTest extends TestCase
{
    public function test_admin_holds_everything(): void
    {
        self::assertTrue(Authorizer::holds(['admin'], 'admin'));
        self::assertTrue(Authorizer::holds(['admin'], 'users:write'));
        self::assertTrue(Authorizer::holds(['admin'], 'anything:read'));
    }

    public function test_an_exact_grant_is_held(): void
    {
        self::assertTrue(Authorizer::holds(['users:write'], 'users:write'));
        self::assertTrue(Authorizer::holds(['*:read'], '*:read'));
    }

    public function test_only_admin_holds_admin(): void
    {
        self::assertFalse(Authorizer::holds(['users:write', '*:write'], 'admin'));
    }

    public function test_the_content_wildcard_never_holds_a_management_capability(): void
    {
        // The escalation-at-mint invariant: "write all content" is not "manage the site".
        self::assertFalse(Authorizer::holds(['*:write'], 'users:write'));
        self::assertFalse(Authorizer::holds(['*:write'], 'schema:write'));
        self::assertFalse(Authorizer::holds(['*:read'], 'settings:read'));
    }

    public function test_content_wildcard_and_write_implies_read(): void
    {
        self::assertTrue(Authorizer::holds(['*:write'], 'posts:write'));
        self::assertTrue(Authorizer::holds(['*:write'], 'posts:read'), 'write implies read');
        self::assertTrue(Authorizer::holds(['posts:write'], 'posts:read'));
    }

    public function test_nothing_is_held_by_an_empty_set_or_a_malformed_capability(): void
    {
        self::assertFalse(Authorizer::holds([], 'posts:read'));
        self::assertFalse(Authorizer::holds(['admin'], 'garbage'));
        self::assertFalse(Authorizer::holds(['posts:read'], 'posts'), 'no action → holds nothing');
        self::assertFalse(Authorizer::holds(['posts:read'], 'posts:'), 'empty action');
    }
}

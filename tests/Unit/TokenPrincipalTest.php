<?php

declare(strict_types=1);

namespace Nimbus\Tests\Unit;

use Nimbus\Api\ApiToken;
use Nimbus\Api\TokenPrincipal;
use PHPUnit\Framework\TestCase;

final class TokenPrincipalTest extends TestCase
{
    public function test_can_matches_exact_scopes_and_is_per_action(): void
    {
        $p = new TokenPrincipal(1, 'T', ['posts:read', 'pages:write']);

        self::assertTrue($p->can('posts', 'read'));
        self::assertTrue($p->can('pages', 'write'));
        self::assertFalse($p->can('posts', 'write'), 'read does not imply write');
        self::assertFalse($p->can('media', 'read'), 'an ungranted resource is denied');
    }

    public function test_a_wildcard_resource_grants_that_action_everywhere(): void
    {
        $p = new TokenPrincipal(1, 'T', ['*:read']);

        self::assertTrue($p->can('posts', 'read'));
        self::assertTrue($p->can('anything', 'read'));
        self::assertFalse($p->can('posts', 'write'), 'the wildcard is per-action');
    }

    public function test_no_scopes_denies_everything(): void
    {
        self::assertFalse((new TokenPrincipal(1, 'T', []))->can('posts', 'read'));
    }

    public function test_from_token_grants_legacy_read_all_only_when_abilities_are_empty(): void
    {
        $legacy = TokenPrincipal::fromToken(new ApiToken(1, 'Old', [], null));
        self::assertSame(['*:read'], $legacy->scopes);
        self::assertTrue($legacy->can('anything', 'read'));
        self::assertFalse($legacy->can('anything', 'write'), 'the compat grant is read-only');

        $scoped = TokenPrincipal::fromToken(new ApiToken(2, 'New', ['posts:read'], null));
        self::assertSame(['posts:read'], $scoped->scopes, 'an explicitly-scoped token is untouched');
    }
}

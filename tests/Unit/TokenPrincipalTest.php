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

    public function test_admin_is_a_super_grant_over_every_resource_and_action(): void
    {
        $p = new TokenPrincipal(1, 'Root', ['admin']);

        self::assertTrue($p->can('posts', 'read'));
        self::assertTrue($p->can('posts', 'write'));
        self::assertTrue($p->can('schema', 'write'), 'admin covers management capabilities too');
        self::assertTrue($p->can('anything', 'delete'), 'admin is action-agnostic');
    }

    public function test_a_granular_management_capability_is_scoped_to_itself(): void
    {
        $p = new TokenPrincipal(1, 'Schema editor', ['schema:write']);

        self::assertTrue($p->can('schema', 'write'));
        self::assertFalse($p->can('users', 'write'), 'a granular capability grants only itself');
        self::assertFalse($p->can('posts', 'read'), 'and does not leak into content');
    }

    public function test_the_content_wildcard_never_reaches_a_management_capability(): void
    {
        // `*:write` means "write every collection", not "run the whole CMS".
        // Management capabilities escalate privilege and must be granted
        // explicitly (or via admin), so the content wildcard must not confer
        // them — else a content-write-all token could mint tokens or add users.
        $p = new TokenPrincipal(1, 'Content bot', ['*:write', '*:read']);

        self::assertTrue($p->can('posts', 'write'), 'the wildcard still grants writing any collection');
        self::assertTrue($p->can('anything', 'read'));
        self::assertFalse($p->can('users', 'write'), 'the content wildcard must not grant user management');
        self::assertFalse($p->can('tokens', 'write'), 'nor token minting');
        self::assertFalse($p->can('settings', 'write'), 'nor settings');
        self::assertFalse($p->can('schema', 'write'), 'nor schema changes');
        self::assertFalse($p->can('media', 'read'), 'management reads are explicit too');
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

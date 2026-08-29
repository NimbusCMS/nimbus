<?php

declare(strict_types=1);

namespace Nimbus\Tests\Unit;

use Nimbus\Auth\Authorizer;
use PHPUnit\Framework\TestCase;

/**
 * The one authorization decision (ADR 0011), shared by users and tokens: admin
 * super-grant, exact grants, management-exact-only, the content wildcard, and
 * content write-implies-read.
 */
final class AuthorizerTest extends TestCase
{
    public function test_admin_grants_everything(): void
    {
        self::assertTrue(Authorizer::can(['admin'], 'posts', 'write'));
        self::assertTrue(Authorizer::can(['admin'], 'users', 'write'));
        self::assertTrue(Authorizer::can(['admin'], 'anything', 'delete'));
    }

    public function test_exact_grant_and_deny_by_default(): void
    {
        self::assertTrue(Authorizer::can(['posts:read'], 'posts', 'read'));
        self::assertFalse(Authorizer::can(['posts:read'], 'posts', 'write'), 'read does not imply write');
        self::assertFalse(Authorizer::can([], 'posts', 'read'), 'nothing granted, nothing allowed');
    }

    public function test_content_write_implies_read(): void
    {
        self::assertTrue(Authorizer::can(['posts:write'], 'posts', 'read'), 'you can read content you can write');
        self::assertTrue(Authorizer::can(['*:write'], 'anything', 'read'), 'the write wildcard implies read too');
    }

    public function test_management_capabilities_are_exact_only(): void
    {
        self::assertTrue(Authorizer::can(['schema:write'], 'schema', 'write'));
        // The content wildcard never reaches management, and write never implies a management read.
        self::assertFalse(Authorizer::can(['*:write', '*:read'], 'users', 'write'), 'the wildcard cannot grant management');
        self::assertFalse(Authorizer::can(['media:write'], 'media', 'read'), 'management read/write stay independent');
        // roles is management too: only an exact grant (or admin) manages roles.
        self::assertTrue(Authorizer::can(['roles:write'], 'roles', 'write'));
        self::assertFalse(Authorizer::can(['*:write'], 'roles', 'write'), 'the wildcard cannot grant roles management');
    }

    public function test_content_wildcard_grants_every_collection(): void
    {
        self::assertTrue(Authorizer::can(['*:write'], 'posts', 'write'));
        self::assertTrue(Authorizer::can(['*:write'], 'events', 'write'), 'including collections not named — e.g. future ones');
        self::assertFalse(Authorizer::can(['posts:write'], 'events', 'write'), 'an explicit grant does not spill to other collections');
    }

    protected function tearDown(): void
    {
        // The plugin-management set is process-static; reset so a test that
        // installs it cannot leak into the next.
        Authorizer::reset();
        parent::tearDown();
    }

    public function test_a_plugin_declared_management_capability_is_wildcard_immune(): void
    {
        // H2a / A1 (Critical): before this, a plugin-invented resource fell through
        // to the content wildcard, so any editor or all-collections token could move
        // money-grade stock. Once installed as management it is exact-or-admin only.
        Authorizer::useManagement(['nimbuscms.inventory']);

        self::assertFalse(Authorizer::can(['*:write'], 'nimbuscms.inventory', 'write'), 'the content wildcard cannot move stock');
        self::assertFalse(Authorizer::can(['*:read'], 'nimbuscms.inventory', 'read'), 'nor read it');
        self::assertFalse(Authorizer::can(['nimbuscms.inventory:write'], 'nimbuscms.inventory', 'read'), 'plugin management read/write stay independent, like core');
        self::assertTrue(Authorizer::can(['nimbuscms.inventory:write'], 'nimbuscms.inventory', 'write'), 'an exact grant works');
        self::assertTrue(Authorizer::can(['admin'], 'nimbuscms.inventory', 'write'), 'admin still grants everything');
    }

    public function test_holds_extends_subset_only_granting_to_plugin_capabilities(): void
    {
        // You can only grant what you hold: a *:write holder does NOT hold a plugin
        // management capability, so it cannot mint/grant it.
        Authorizer::useManagement(['nimbuscms.inventory']);

        self::assertFalse(Authorizer::holds(['*:write'], 'nimbuscms.inventory:write'), 'a content wildcard cannot grant plugin management');
        self::assertTrue(Authorizer::holds(['nimbuscms.inventory:write'], 'nimbuscms.inventory:write'));
        self::assertFalse(Authorizer::holds(['nimbuscms.inventory:write'], 'nimbuscms.inventory:read'), 'management read/write are independent — write does not hold read (unlike content)');
        self::assertTrue(Authorizer::holds(['admin'], 'nimbuscms.inventory:write'));
    }

    public function test_is_management_covers_core_and_plugin_but_not_content(): void
    {
        Authorizer::useManagement(['nimbuscms.inventory']);

        self::assertTrue(Authorizer::isManagement('schema'), 'a core management resource');
        self::assertTrue(Authorizer::isManagement('nimbuscms.inventory'), 'a plugin-declared one');
        self::assertFalse(Authorizer::isManagement('posts'), 'a content collection is not management');
    }

    public function test_use_management_replaces_rather_than_accumulates(): void
    {
        // The one boot caller composes the full set each time; a second call must
        // not leave a stale resource behind.
        Authorizer::useManagement(['nimbuscms.inventory']);
        Authorizer::useManagement(['nimbuscms.forms']);

        self::assertFalse(Authorizer::isManagement('nimbuscms.inventory'), 'the earlier set was replaced');
        self::assertTrue(Authorizer::isManagement('nimbuscms.forms'));
    }
}

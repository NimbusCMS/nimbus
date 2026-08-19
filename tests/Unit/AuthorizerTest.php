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
}

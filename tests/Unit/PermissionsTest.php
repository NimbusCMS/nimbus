<?php

declare(strict_types=1);

namespace Nimbus\Tests\Unit;

use Nimbus\Auth\User;
use Nimbus\Content\Collection;
use Nimbus\Content\Permissions;
use PHPUnit\Framework\TestCase;

final class PermissionsTest extends TestCase
{
    private function user(string $role): User
    {
        return new User(1, 'U', 'u@example.com', $role);
    }

    /** @param string[] $manageRoles */
    private function collection(array $manageRoles): Collection
    {
        return new Collection(1, 'c', 'C', '#', '', [], ['permissions' => ['manage' => $manageRoles]]);
    }

    public function test_admin_can_manage_any_collection(): void
    {
        self::assertTrue(Permissions::canManage($this->user('admin'), $this->collection([])));
    }

    public function test_granted_role_can_manage(): void
    {
        self::assertTrue(Permissions::canManage($this->user('editor'), $this->collection(['editor'])));
    }

    public function test_ungranted_role_cannot_manage(): void
    {
        self::assertFalse(Permissions::canManage($this->user('author'), $this->collection(['editor'])));
    }

    public function test_guest_cannot_manage(): void
    {
        self::assertFalse(Permissions::canManage(null, $this->collection(['editor'])));
    }

    public function test_is_admin(): void
    {
        self::assertTrue(Permissions::isAdmin($this->user('admin')));
        self::assertFalse(Permissions::isAdmin($this->user('editor')));
        self::assertFalse(Permissions::isAdmin(null));
    }
}

<?php

declare(strict_types=1);

namespace Nimbus\Content;

use Nimbus\Auth\User;

/**
 * Per-collection authorization. Admins can do everything; other roles can view
 * (in the admin) but may only manage entries if the collection grants their
 * role. This is the enforcement point the granular RBAC UI will build on.
 */
final class Permissions
{
    /** Built-in roles available when defining collections / users. */
    public const ROLES = ['admin', 'editor', 'author'];

    public static function isAdmin(?User $user): bool
    {
        return $user !== null && $user->role === 'admin';
    }

    public static function canView(?User $user, Collection $collection): bool
    {
        return $user !== null; // any signed-in admin user can browse content
    }

    public static function canManage(?User $user, Collection $collection): bool
    {
        if ($user === null) {
            return false;
        }
        return self::isAdmin($user) || in_array($user->role, $collection->managerRoles(), true);
    }
}

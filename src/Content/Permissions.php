<?php

declare(strict_types=1);

namespace Nimbus\Content;

use Nimbus\Auth\User;

/**
 * The **legacy** per-collection authorization (three fixed roles + a collection's
 * manage-list). Superseded by capability-based roles (ADR 0011): the admin now
 * authorizes through {@see \Nimbus\Auth\Gate}. This remains only as the Gate's
 * fallback while an install is un-seeded, and holds the `ROLES` constant the
 * seeder uses — so admins can do everything and other roles manage only the
 * collections that granted them, exactly as before, until `roles:seed` runs.
 */
final class Permissions
{
    /** Built-in roles available when defining collections / users. */
    public const ROLES = ['admin', 'editor', 'author'];

    public static function isAdmin(?User $user): bool
    {
        return $user !== null && $user->role === 'admin';
    }

    public static function canManage(?User $user, Collection $collection): bool
    {
        if ($user === null) {
            return false;
        }
        return self::isAdmin($user) || in_array($user->role, $collection->managerRoles(), true);
    }
}

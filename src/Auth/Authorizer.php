<?php

declare(strict_types=1);

namespace Nimbus\Auth;

/**
 * The one authorization decision function (ADR 0011). Given a capability set and
 * a `resource`/`action`, it answers "may this be done?" deny-by-default — used
 * by every principal, human (a user's roles) or machine (a token's scopes), so
 * people and agents are judged by identical rules.
 *
 * The rules, in order:
 * - `admin` grants everything.
 * - an exact `{resource}:{action}` grant always suffices.
 * - a **management** capability (schema/media/users/tokens/settings/roles) needs
 *   an exact grant or `admin` — the content wildcard never reaches it, so
 *   "write all my content" can't manage the site.
 * - for **content** (any other resource), the wildcard `*:{action}` grants that
 *   action on every collection, and `{resource}:write` **implies**
 *   `{resource}:read` — you cannot edit content you cannot read.
 */
final class Authorizer
{
    /** Capabilities that escalate the site — granted only exactly (or by `admin`). */
    public const MANAGEMENT = ['schema', 'media', 'users', 'tokens', 'settings', 'roles'];

    /** @param list<string> $capabilities */
    public static function can(array $capabilities, string $resource, string $action): bool
    {
        if (in_array('admin', $capabilities, true)) {
            return true;
        }
        if (in_array("{$resource}:{$action}", $capabilities, true)) {
            return true;
        }
        if (in_array($resource, self::MANAGEMENT, true)) {
            return false;
        }
        if (in_array("*:{$action}", $capabilities, true)) {
            return true;
        }
        // Content only: writing a collection implies reading it.
        return $action === 'read'
            && (in_array("{$resource}:write", $capabilities, true) || in_array('*:write', $capabilities, true));
    }
}

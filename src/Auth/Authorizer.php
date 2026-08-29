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
 * - a **management** capability (schema/media/users/tokens/settings/roles, plus
 *   any a plugin declared — ADR 0015) needs an exact grant or `admin` — the
 *   content wildcard never reaches it, so "write all my content" can't manage the
 *   site, nor move a plugin's money-grade resources (e.g. `inventory:write`).
 * - for **content** (any other resource), the wildcard `*:{action}` grants that
 *   action on every collection, and `{resource}:write` **implies**
 *   `{resource}:read` — you cannot edit content you cannot read.
 */
final class Authorizer
{
    /** Capabilities that escalate the site — granted only exactly (or by `admin`). */
    public const MANAGEMENT = ['schema', 'media', 'users', 'tokens', 'settings', 'roles'];

    /**
     * Plugin-declared management resources (ADR 0015), installed once at boot from
     * the frozen {@see CapabilityRegistry} and read-only thereafter. These extend
     * the wildcard-immune set exactly like the core {@see MANAGEMENT} const, so a
     * plugin capability (e.g. `nimbuscms.inventory:write`) is unreachable by the
     * content `*:write` wildcard. Composed in one place (Application::loadPlugins);
     * a drift test asserts {@see useManagement()} has no other caller.
     *
     * @var list<string>
     */
    private static array $pluginManagement = [];

    /**
     * Install the plugin-declared management resources. Called once, at the end of
     * plugin load, before the first request is served — never at request time.
     *
     * @param list<string> $resources plugin ids that are management capabilities
     */
    public static function useManagement(array $resources): void
    {
        self::$pluginManagement = array_values($resources);
    }

    /** Clear the plugin-declared set. Test isolation only — production installs once. */
    public static function reset(): void
    {
        self::$pluginManagement = [];
    }

    /** Is this resource a management capability — core or plugin-declared — and so wildcard-immune? */
    public static function isManagement(string $resource): bool
    {
        return in_array($resource, self::MANAGEMENT, true)
            || in_array($resource, self::$pluginManagement, true);
    }

    /** @param list<string> $capabilities */
    public static function can(array $capabilities, string $resource, string $action): bool
    {
        if (in_array('admin', $capabilities, true)) {
            return true;
        }
        if (in_array("{$resource}:{$action}", $capabilities, true)) {
            return true;
        }
        if (self::isManagement($resource)) {
            return false;
        }
        if (in_array("*:{$action}", $capabilities, true)) {
            return true;
        }
        // Content only: writing a collection implies reading it.
        return $action === 'read'
            && (in_array("{$resource}:write", $capabilities, true) || in_array('*:write', $capabilities, true));
    }

    /**
     * Does a capability set *hold* a single capability string — the grant-side
     * question behind every subset-only check ("you can only grant what you
     * hold")? `admin` holds everything; otherwise the capability is a
     * `resource:action` grant judged by {@see can()} (so it inherits the
     * management-immunity rule — a `*:write` holder does not hold `users:write`).
     * A malformed capability holds nothing.
     *
     * This is the one predicate behind `Gate::holds` (human actors), the MCP
     * `TokensToolset`/`UsersToolset` guards (token actors), and admin role
     * granting — people and agents judged by identical rules.
     *
     * @param list<string> $capabilities
     */
    public static function holds(array $capabilities, string $capability): bool
    {
        if ($capability === 'admin') {
            return in_array('admin', $capabilities, true);
        }
        $parts = explode(':', $capability, 2);
        return count($parts) === 2 && $parts[1] !== '' && self::can($capabilities, $parts[0], $parts[1]);
    }
}

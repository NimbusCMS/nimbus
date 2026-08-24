<?php

declare(strict_types=1);

namespace Nimbus\Plugin;

/**
 * The state of one discovered plugin package, for display.
 *
 * This is the whole picture of a package — healthy, disabled, or broken —
 * whereas PluginDiagnostic records only why something did *not* run. The admin
 * Plugins page renders these; nothing here carries a stack trace, a filesystem
 * path, or any live object, so it is safe to hand straight to a template.
 *
 * Built by the loader during discovery. The admin controller never constructs
 * one and never reads installed.json itself.
 */
final readonly class PluginStatus
{
    /** Enabled and registered cleanly. */
    public const HEALTHY = 'healthy';

    /** Installed, but switched off in configuration. */
    public const DISABLED = 'disabled';

    /** register() threw — the plugin is installed but not active. */
    public const FAILED = 'failed';

    /** The manifest was unusable (no id/class, class missing, not a Plugin). */
    public const INVALID = 'invalid';

    /** Another installed package already claimed this id. */
    public const DUPLICATE_ID = 'duplicate_id';

    public function __construct(
        public string $id,
        public string $packageName,
        public string $displayName,
        public string $version,
        public bool $enabled,
        public string $state,
        public string $message = '',
        public bool $official = false,
    ) {
    }

    /** A short human label for the state, for a badge. */
    public function stateLabel(): string
    {
        return match ($this->state) {
            self::HEALTHY      => 'Enabled',
            self::DISABLED     => 'Disabled',
            self::FAILED       => 'Failed',
            self::INVALID      => 'Invalid',
            self::DUPLICATE_ID => 'Duplicate ID',
            default            => ucfirst($this->state),
        };
    }

    /** Whether this state should read as a problem in the UI. */
    public function isProblem(): bool
    {
        return $this->state === self::FAILED
            || $this->state === self::INVALID
            || $this->state === self::DUPLICATE_ID;
    }

    /**
     * The provider label states the neutral *fact* — that the package is in the
     * `nimbuscms/` Composer namespace — not a trust claim. `$official` is only a
     * vendor-prefix match (any VCS/path package can name itself `nimbuscms/*`), so
     * calling it "Official" would over-promise a verification Nimbus can't do
     * (PLUG-13). "Community" covers everything else.
     */
    public function providerLabel(): string
    {
        return $this->official ? 'nimbuscms namespace' : 'Community';
    }
}

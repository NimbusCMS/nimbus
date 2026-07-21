<?php

declare(strict_types=1);

namespace Nimbus\Plugin;

/**
 * Why a discovered package did not become a running plugin.
 *
 * The loader reports rather than silently skipping. A plugin that is installed
 * but quietly ignored is the hardest kind of problem to debug — everything
 * looks fine and nothing happens.
 */
final readonly class PluginDiagnostic
{
    public const INVALID_MANIFEST = 'invalid_manifest';
    public const MISSING_CLASS    = 'missing_class';
    public const NOT_A_PLUGIN     = 'not_a_plugin';
    public const DUPLICATE_ID     = 'duplicate_id';
    public const DISABLED         = 'disabled';
    public const REGISTER_FAILED  = 'register_failed';

    public function __construct(
        public string $package,
        public string $reason,
        public string $message,
    ) {
    }

    /** Disabled is a choice, not a fault — useful to list, not to warn about. */
    public function isFailure(): bool
    {
        return $this->reason !== self::DISABLED;
    }

    public function __toString(): string
    {
        return "[{$this->reason}] {$this->package}: {$this->message}";
    }
}

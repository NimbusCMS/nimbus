<?php

declare(strict_types=1);

namespace Nimbus\Content;

use RuntimeException;

/**
 * Thrown when a write path asks for a field type nobody registered — normally
 * because the plugin providing it is uninstalled or deactivated while entries
 * still reference it.
 *
 * Silently substituting a text field here would quietly rewrite stored values
 * through the wrong normalizer, so the write path refuses instead. Admin
 * *display* code uses FieldTypeRegistry::forDisplay() to show the data safely.
 */
final class UnknownFieldType extends RuntimeException
{
    public function __construct(public readonly string $type)
    {
        parent::__construct("Unknown field type: \"{$type}\". Is the plugin that provides it installed and active?");
    }
}

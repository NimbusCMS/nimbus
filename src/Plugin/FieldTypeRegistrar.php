<?php

declare(strict_types=1);

namespace Nimbus\Plugin;

use Nimbus\Content\FieldType;
use Nimbus\Content\FieldTypeRegistry;

/**
 * The field-type capability, as a plugin sees it.
 *
 * Narrower than the registry on purpose. A plugin can add a type and nothing
 * else — it cannot read the whole registry, and it cannot remove another
 * provider's types.
 *
 * The provider id is bound here by the loader rather than passed in by the
 * plugin. Letting a plugin name its own provider would let it claim to be
 * "core", which matters because rollback is provider-scoped: a plugin that
 * registered under someone else's name could get their types removed when it
 * failed.
 */
final class FieldTypeRegistrar
{
    public function __construct(
        private FieldTypeRegistry $registry,
        private string $pluginId,
    ) {
    }

    /**
     * Add a field type. Fails if another provider already owns the key —
     * first registration wins.
     *
     * @throws \Nimbus\Content\DuplicateFieldType
     */
    public function register(FieldType $type): void
    {
        $this->registry->register($type, $this->pluginId);
    }
}

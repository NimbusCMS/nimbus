<?php

declare(strict_types=1);

namespace Nimbus\Content;

use LogicException;

/**
 * Two providers claimed the same field-type key.
 *
 * The policy is first registration wins, and the loser fails loudly at boot.
 * Silent replacement is the alternative, and it is worse in every direction:
 * a plugin could hijack "text" and reinterpret every existing entry, or two
 * unrelated plugins could fight over a key with the winner decided by
 * Composer's autoload order.
 *
 * Overrides and priorities are deliberately not supported. If plugins ever
 * need to decorate an existing type, that is a different feature with a
 * different contract.
 */
final class DuplicateFieldType extends LogicException
{
    public function __construct(
        public readonly string $type,
        public readonly string $existingProvider = 'core',
        public readonly string $attemptedProvider = 'unknown',
    ) {
        parent::__construct(sprintf(
            'Field type "%s" is already provided by %s; %s tried to register it again. '
            . 'Field-type keys must be unique — prefix plugin types to avoid collisions.',
            $type,
            $existingProvider,
            $attemptedProvider,
        ));
    }
}

<?php

declare(strict_types=1);

namespace Nimbus\Content\FieldTypes;

use Nimbus\Content\Field;

/**
 * Stands in for a field type whose provider is missing, so the admin can still
 * open the collection and see what is stored.
 *
 * It is deliberately inert: normalize() hands the value straight back (nothing
 * is coerced or erased) and validate() always fails, so a save that would write
 * through the wrong type is blocked until the provider is restored. Used only
 * for display — write paths get UnknownFieldType instead.
 */
final class MissingType extends BaseType
{
    public function __construct(private string $missing)
    {
    }

    public function type(): string
    {
        return $this->missing;
    }

    public function label(): string
    {
        return $this->missing . ' (unavailable)';
    }

    public function renderInput(Field $field, mixed $value): string
    {
        return '<div class="nb-missing-type">'
            . '<p><strong>Field type “' . $this->e($this->missing) . '” is unavailable.</strong> '
            . 'Install or reactivate the plugin that provides it to edit this field. '
            . 'The stored value is preserved and is shown below read-only.</p>'
            . '<pre class="nb-missing-type__value">' . $this->e($this->stringify($value)) . '</pre>'
            . '</div>';
    }

    public function renderCell(Field $field, mixed $value): string
    {
        return '<span class="nb-missing-type__cell" title="Field type &ldquo;'
            . $this->e($this->missing) . '&rdquo; is unavailable">⚠ ' . $this->e($this->stringify($value)) . '</span>';
    }

    /** Never coerce — whatever is stored stays exactly as it is. */
    public function normalize(mixed $input): mixed
    {
        return $input;
    }

    public function validate(Field $field, mixed $value): ?string
    {
        return 'The “' . $this->missing . '” field type is unavailable, so this entry cannot be saved.';
    }

    private function stringify(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }
        return is_scalar($value) ? (string) $value : (json_encode($value) ?: '—');
    }
}

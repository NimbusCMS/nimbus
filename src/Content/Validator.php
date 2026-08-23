<?php

declare(strict_types=1);

namespace Nimbus\Content;

/**
 * Validates an entry's field values against its collection. Required/empty is
 * handled here centrally; type-specific rules (format, choice membership) are
 * delegated to each FieldType::validate() — so a plugin's field type validates
 * itself with no changes here.
 */
final class Validator
{
    /** Hard ceiling on any scalar string field value (DoS backstop; a field's maxlength can only lower it). */
    private const MAX_SCALAR_LENGTH = 100_000;

    public function __construct(private FieldTypeRegistry $types)
    {
    }

    /**
     * @param array<string,mixed> $values normalized field values keyed by handle
     * @return array<string,FieldError> handle => structured error (empty = valid)
     */
    public function validate(Collection $collection, array $values): array
    {
        $errors = [];
        foreach ($collection->fields as $field) {
            $value = $values[$field->handle] ?? null;

            if ($this->isEmpty($value)) {
                if ($field->required) {
                    $errors[$field->handle] = FieldError::required($field->label . ' is required.');
                }
                continue;
            }

            // Type-agnostic hard ceiling on any scalar STRING value — the DoS
            // backstop for the JSON `data` column, covering field types with a
            // custom validate() that has no length rule of its own (e.g. url,
            // email). Only strings are checked, so number/boolean/relation are
            // untouched; text/textarea narrow this further in their own validate().
            if (is_string($value) && mb_strlen($value) > self::MAX_SCALAR_LENGTH) {
                $errors[$field->handle] = FieldError::invalid($field->label . ' is too long.');
                continue;
            }

            // A field type's validate() returns a human string; core owns the
            // code, wrapping any failure as the generic `invalid` (the plugin
            // string never becomes a code). More specific codes are an additive
            // refinement for later — see FieldError.
            $error = $this->types->get($field->type)->validate($field, $value);
            if ($error !== null) {
                $errors[$field->handle] = FieldError::invalid($error);
            }
        }
        return $errors;
    }

    private function isEmpty(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [];
    }
}

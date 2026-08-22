<?php

declare(strict_types=1);

namespace Nimbus\Content;

/**
 * Outcome of EntryService::save — either a saved entry id, or a failure carrying
 * structured, machine-readable errors.
 *
 * A failure has a top-level {@see $code} (`invalid` for per-field validation,
 * `missing_provider` when a field type's plugin is unavailable) with a top-level
 * {@see $message}, and, for `invalid`, a per-field {@see $errors} map of
 * {@see FieldError}. {@see messages()} projects the map to plain strings for the
 * admin, which shows prose (the codes are for the API/MCP consumers).
 */
final readonly class SaveEntryResult
{
    /** @param array<string,FieldError> $errors */
    private function __construct(
        public bool $successful,
        public ?int $entryId,
        public array $errors,
        public EntryInput $input,
        public string $code = '',
        public string $message = '',
    ) {
    }

    public static function ok(int $entryId, EntryInput $input): self
    {
        return new self(true, $entryId, [], $input);
    }

    /**
     * A per-field validation failure (top-level code `invalid`).
     *
     * @param array<string,FieldError> $errors
     */
    public static function invalid(array $errors, EntryInput $input): self
    {
        return new self(false, null, $errors, $input, 'invalid');
    }

    /** A field type's provider is unavailable — a top-level, non-field failure. */
    public static function missingProvider(string $message, EntryInput $input): self
    {
        return new self(false, null, [], $input, 'missing_provider', $message);
    }

    /** @return array<string,string> handle => human message, for the admin form */
    public function messages(): array
    {
        return array_map(static fn (FieldError $e): string => $e->message, $this->errors);
    }
}

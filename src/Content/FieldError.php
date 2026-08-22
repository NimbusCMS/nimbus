<?php

declare(strict_types=1);

namespace Nimbus\Content;

/**
 * One field's validation failure: a machine-readable {@see $code} plus a human
 * {@see $message}. The code is what an agent branches on; the message is what a
 * person reads.
 *
 * The **code is always assigned by core** — never by a plugin or by user input.
 * A plugin field type's `validate()` still returns a human string, which the
 * {@see Validator} wraps as the generic `invalid` code; the plugin's string only
 * ever lands in `message`. The code vocabulary is small, stable and
 * additive-only (see docs/COMPATIBILITY.md): `required`, `invalid`, and the
 * top-level `missing_provider`. A client must treat an unknown code as `invalid`.
 */
final readonly class FieldError
{
    public function __construct(
        public string $code,
        public string $message,
    ) {
    }

    /** A required field left empty. */
    public static function required(string $message): self
    {
        return new self('required', $message);
    }

    /** A value that failed a field type's own validation (format, choice, type). */
    public static function invalid(string $message): self
    {
        return new self('invalid', $message);
    }

    /** @return array{code:string,message:string} the wire shape for API + MCP */
    public function toArray(): array
    {
        return ['code' => $this->code, 'message' => $this->message];
    }
}

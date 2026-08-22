<?php

declare(strict_types=1);

namespace Nimbus\Api;

use Nimbus\Content\FieldError;

/**
 * The transport-agnostic result of a content operation (EntryOperations).
 *
 * It carries everything either transport needs to render a response — the
 * rendered view(s), the entry's id and version (for an ETag), pagination meta,
 * and, on failure, a code-bearing message or field errors — without knowing
 * anything about HTTP or JSON-RPC. The transport switches on {@see $status}.
 */
final readonly class EntryOpResult
{
    /**
     * @param array<string,mixed>|list<array<string,mixed>>|null $data rendered entry view, list of views, or null (delete)
     * @param array<string,mixed> $meta pagination meta (list only)
     * @param array<string,FieldError> $errors per-field validation errors (Invalid only)
     * @param string $code top-level machine code for a failure (e.g. `invalid`, `missing_provider`)
     */
    private function __construct(
        public EntryOpStatus $status,
        public array|null $data = null,
        public ?int $entryId = null,
        public ?int $version = null,
        public array $meta = [],
        public array $errors = [],
        public string $message = '',
        public string $code = '',
    ) {
    }

    /**
     * @param array<string,mixed>|list<array<string,mixed>>|null $data
     * @param array<string,mixed> $meta
     */
    public static function ok(array|null $data, ?int $entryId = null, ?int $version = null, array $meta = []): self
    {
        return new self(EntryOpStatus::Ok, $data, $entryId, $version, $meta);
    }

    public static function forbidden(string $message): self
    {
        return new self(EntryOpStatus::Forbidden, message: $message);
    }

    public static function notFound(string $message): self
    {
        return new self(EntryOpStatus::NotFound, message: $message);
    }

    /**
     * A validation failure: per-field {@see FieldError}s and a top-level machine
     * code (`invalid` for field errors, `missing_provider` for an unavailable
     * field-type plugin — which carries a message and no field entries).
     *
     * @param array<string,FieldError> $errors
     */
    public static function invalid(array $errors, string $code = 'invalid', string $message = ''): self
    {
        return new self(EntryOpStatus::Invalid, errors: $errors, message: $message, code: $code);
    }

    public static function preconditionRequired(string $message): self
    {
        return new self(EntryOpStatus::PreconditionRequired, message: $message);
    }

    public static function preconditionFailed(string $message): self
    {
        return new self(EntryOpStatus::PreconditionFailed, message: $message);
    }

    public function isOk(): bool
    {
        return $this->status === EntryOpStatus::Ok;
    }
}

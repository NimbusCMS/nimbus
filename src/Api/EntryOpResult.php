<?php

declare(strict_types=1);

namespace Nimbus\Api;

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
     * @param array<string,string> $errors field validation errors (Invalid only)
     */
    private function __construct(
        public EntryOpStatus $status,
        public array|null $data = null,
        public ?int $entryId = null,
        public ?int $version = null,
        public array $meta = [],
        public array $errors = [],
        public string $message = '',
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

    /** @param array<string,string> $errors */
    public static function invalid(array $errors): self
    {
        return new self(EntryOpStatus::Invalid, errors: $errors);
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

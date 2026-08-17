<?php

declare(strict_types=1);

namespace Nimbus\Api;

use Closure;
use Nimbus\Content\Collection;
use Nimbus\Content\CollectionRepository;
use Nimbus\Content\EntryInput;
use Nimbus\Content\EntryRepository;
use Nimbus\Content\EntryService;
use Nimbus\Content\EntryView;
use Nimbus\Content\FieldTypeRegistry;
use Nimbus\Content\Publication;
use Nimbus\Content\RelationRepository;
use Nimbus\Database\Connection;
use Nimbus\Media\MediaRepository;
use Nimbus\Support\CoreEvents;
use Nimbus\Support\EventDispatcher;

/**
 * The one scope-checked content path (ADR 0009).
 *
 * Every content read and write — list, get, create, update, delete — lives here
 * exactly once, so the HTTP API and MCP call the same implementation instead of
 * two that could drift on authorization, concurrency, validation or auditing.
 * It knows nothing about HTTP or JSON-RPC: it takes a {@see TokenPrincipal}, a
 * plain payload and a {@see Precondition}, and returns an {@see EntryOpResult}
 * the caller maps to its own wire format.
 *
 * The guarantees it centralizes:
 * - **Deny-by-default authz**, checked *before* existence, so a token cannot
 *   tell "forbidden" from "absent" and enumerate what lies outside its scope.
 * - **Mass-assignment safety**, via the allow-list field binding (only declared
 *   fields are read; unknown keys are ignored) — writes reuse {@see EntryService}.
 * - **Optimistic concurrency**, so a write must present the version it read.
 * - **An audit trail**: denials and writes emit the same best-effort events
 *   regardless of transport.
 */
final class EntryOperations
{
    /** The largest page a client may request; keeps a single response bounded. */
    public const MAX_PER_PAGE = 50;
    public const DEFAULT_PER_PAGE = 20;

    private CollectionRepository $collections;
    private EntryRepository $entries;
    private RelationRepository $relations;
    private EntryView $view;
    private EntryService $entryService;

    public function __construct(
        Connection $db,
        private FieldTypeRegistry $types,
        private EventDispatcher $events,
    ) {
        $this->collections  = new CollectionRepository($db);
        $this->entries      = new EntryRepository($db);
        $this->relations    = new RelationRepository($db);
        $this->view         = new EntryView($types, $this->relations, new MediaRepository($db));
        $this->entryService = new EntryService($db, $this->entries, $this->relations, $types, $events);
    }

    /** A page of a collection's live entries, newest first. Requires `{handle}:read`. */
    public function list(TokenPrincipal $principal, EntryOpContext $ctx, string $handle, int $page, int $perPage): EntryOpResult
    {
        if (!$this->allowed($principal, $ctx, $handle, 'read')) {
            return EntryOpResult::forbidden("This token cannot read \"{$handle}\".");
        }
        $collection = $this->collections->findByHandle($handle);
        if ($collection === null) {
            return EntryOpResult::notFound("No collection with handle \"{$handle}\".");
        }

        $perPage = $this->boundPerPage($perPage);
        $page    = max(1, $page);
        $total   = $this->entries->countLive($collection->id);
        $rows    = $this->entries->liveForCollection($collection->id, $perPage, ($page - 1) * $perPage);

        return EntryOpResult::ok(
            $this->view->many($collection, $rows, $this->scopeFilter($principal)),
            meta: [
                'page'        => $page,
                'per_page'    => $perPage,
                'total'       => $total,
                'total_pages' => $total === 0 ? 0 : (int) ceil($total / $perPage),
            ],
        );
    }

    /** A single live entry by slug. Requires `{handle}:read`. */
    public function get(TokenPrincipal $principal, EntryOpContext $ctx, string $handle, string $slug): EntryOpResult
    {
        if (!$this->allowed($principal, $ctx, $handle, 'read')) {
            return EntryOpResult::forbidden("This token cannot read \"{$handle}\".");
        }
        $collection = $this->collections->findByHandle($handle);
        if ($collection === null) {
            return EntryOpResult::notFound("No collection with handle \"{$handle}\".");
        }

        $row = $this->entries->findLiveBySlug($collection->id, $slug);
        if ($row === null) {
            // A draft, a scheduled-but-not-due, or a genuinely absent entry all
            // look the same from outside — nothing to distinguish leaks.
            return EntryOpResult::notFound("No published entry \"{$slug}\" in \"{$handle}\".");
        }

        return EntryOpResult::ok(
            $this->view->one($collection, $row, $this->scopeFilter($principal)),
            entryId: (int) $row['id'],
            version: (int) $row['version'],
        );
    }

    /**
     * Create an entry from a payload. Requires `{handle}:write`.
     *
     * @param array<string,mixed> $payload the write body: `fields`, and optional `title`/`slug`/`status`/`published_at`
     */
    public function create(TokenPrincipal $principal, EntryOpContext $ctx, string $handle, array $payload): EntryOpResult
    {
        if (!$this->allowed($principal, $ctx, $handle, 'write')) {
            return EntryOpResult::forbidden("This token cannot write \"{$handle}\".");
        }
        $collection = $this->collections->findByHandle($handle);
        if ($collection === null) {
            return EntryOpResult::notFound("No collection with handle \"{$handle}\".");
        }

        $result = $this->entryService->save($collection, $this->inputFrom($payload, $collection, null), null, null);
        if (!$result->successful) {
            return EntryOpResult::invalid($result->errors);
        }

        $row = $this->entries->find($collection->id, (int) $result->entryId) ?? [];
        $this->announceWrite($ctx, $principal, $collection, 'create', $row);
        return $this->entityResult($principal, $collection, $row);
    }

    /**
     * Update an entry by slug. Requires `{handle}:write` and a satisfied precondition.
     *
     * @param array<string,mixed> $payload
     */
    public function update(TokenPrincipal $principal, EntryOpContext $ctx, string $handle, string $slug, array $payload, Precondition $precondition): EntryOpResult
    {
        if (!$this->allowed($principal, $ctx, $handle, 'write')) {
            return EntryOpResult::forbidden("This token cannot write \"{$handle}\".");
        }
        $collection = $this->collections->findByHandle($handle);
        if ($collection === null) {
            return EntryOpResult::notFound("No collection with handle \"{$handle}\".");
        }
        $row = $this->entries->findBySlug($collection->id, $slug);
        if ($row === null) {
            return EntryOpResult::notFound("No entry \"{$slug}\" in \"{$handle}\".");
        }

        $failed = $this->checkPrecondition($precondition, (int) $row['id'], (int) $row['version']);
        if ($failed !== null) {
            return $failed;
        }

        $result = $this->entryService->save($collection, $this->inputFrom($payload, $collection, $row), (int) $row['id'], null);
        if (!$result->successful) {
            return EntryOpResult::invalid($result->errors);
        }

        $saved = $this->entries->find($collection->id, (int) $result->entryId) ?? [];
        $this->announceWrite($ctx, $principal, $collection, 'update', $saved);
        return $this->entityResult($principal, $collection, $saved);
    }

    /** Delete an entry by slug. Requires `{handle}:write` and a satisfied precondition. */
    public function delete(TokenPrincipal $principal, EntryOpContext $ctx, string $handle, string $slug, Precondition $precondition): EntryOpResult
    {
        if (!$this->allowed($principal, $ctx, $handle, 'write')) {
            return EntryOpResult::forbidden("This token cannot write \"{$handle}\".");
        }
        $collection = $this->collections->findByHandle($handle);
        if ($collection === null) {
            return EntryOpResult::notFound("No collection with handle \"{$handle}\".");
        }
        $row = $this->entries->findBySlug($collection->id, $slug);
        if ($row === null) {
            return EntryOpResult::notFound("No entry \"{$slug}\" in \"{$handle}\".");
        }

        $failed = $this->checkPrecondition($precondition, (int) $row['id'], (int) $row['version']);
        if ($failed !== null) {
            return $failed;
        }

        $this->entryService->delete($collection, (int) $row['id']);
        $this->announceWrite($ctx, $principal, $collection, 'delete', $row);
        return EntryOpResult::ok(null);
    }

    /**
     * Authorize $principal for $action on $handle, emitting an audit event on a
     * refusal by an otherwise-valid token. The scope check runs before existence
     * so a forbidden handle cannot be told from an absent one.
     */
    private function allowed(TokenPrincipal $principal, EntryOpContext $ctx, string $handle, string $action): bool
    {
        if ($principal->can($handle, $action)) {
            return true;
        }
        $this->events->emitBestEffort(CoreEvents::API_ACCESS_DENIED, [
            'token_id'   => $principal->tokenId,
            'token_name' => $principal->name,
            'resource'   => $handle,
            'action'     => $action,
            'ip'         => $ctx->ip,
            'path'       => $ctx->path,
            'at'         => date('Y-m-d H:i:s'),
        ]);
        return false;
    }

    /** The precondition guard: null when satisfied, or the failing result to return. */
    private function checkPrecondition(Precondition $precondition, int $entryId, int $version): ?EntryOpResult
    {
        return match ($precondition->evaluate($entryId, $version)) {
            PreconditionOutcome::Required  => EntryOpResult::preconditionRequired($precondition->requiredMessage()),
            PreconditionOutcome::Failed    => EntryOpResult::preconditionFailed($precondition->failedMessage()),
            PreconditionOutcome::Satisfied => null,
        };
    }

    /**
     * Announce a write to any audit listener — best-effort, isolated, naming the token.
     *
     * @param array<string,mixed> $row the saved (or, for delete, just-removed) entry row
     */
    private function announceWrite(EntryOpContext $ctx, TokenPrincipal $principal, Collection $collection, string $action, array $row): void
    {
        $this->events->emitBestEffort(CoreEvents::API_ENTRY_WRITTEN, [
            'token_id'   => $principal->tokenId,
            'token_name' => $principal->name,
            'collection' => $collection->handle,
            'entry_id'   => (int) ($row['id'] ?? 0),
            'slug'       => (string) ($row['slug'] ?? ''),
            'action'     => $action,
            'ip'         => $ctx->ip,
            'path'       => $ctx->path,
            'at'         => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * The success result for a write: the saved entry rendered, plus its id and
     * fresh version so the caller can build an ETag.
     *
     * @param array<string,mixed> $row the saved (hydrated) entry row
     */
    private function entityResult(TokenPrincipal $principal, Collection $collection, array $row): EntryOpResult
    {
        return EntryOpResult::ok(
            $this->view->one($collection, $row, $this->scopeFilter($principal)),
            entryId: (int) ($row['id'] ?? 0),
            version: (int) ($row['version'] ?? 1),
        );
    }

    /**
     * Map a payload to an EntryInput. Only the collection's declared fields are
     * read — the allow-list that guards mass-assignment; unknown keys are
     * ignored. On update a field the payload omits keeps its stored value (PATCH
     * semantics), and title/slug/status default to the stored entry.
     *
     * @param array<string,mixed> $payload
     * @param array<string,mixed>|null $existing the stored (hydrated) row on update
     */
    private function inputFrom(array $payload, Collection $collection, ?array $existing): EntryInput
    {
        $fields = is_array($payload['fields'] ?? null) ? $payload['fields'] : [];
        $base   = $existing !== null ? $this->existingValues($collection, $existing) : [];

        $values = [];
        foreach ($collection->fields as $field) {
            $type = $this->types->forDisplay($field->type);
            $values[$field->handle] = array_key_exists($field->handle, $fields)
                ? $type->normalize($fields[$field->handle])
                : ($base[$field->handle] ?? $type->normalize(null));
        }

        $status = is_string($payload['status'] ?? null) && Publication::isStoredStatus($payload['status'])
            ? $payload['status']
            : ($existing !== null ? (string) $existing['status'] : Publication::DRAFT);
        $title = array_key_exists('title', $payload) ? trim((string) $payload['title']) : ($existing !== null ? (string) $existing['title'] : '');
        $slug  = array_key_exists('slug', $payload) ? trim((string) $payload['slug']) : ($existing !== null ? (string) $existing['slug'] : '');
        $publishedAt = is_string($payload['published_at'] ?? null) && trim($payload['published_at']) !== '' ? trim($payload['published_at']) : null;

        return new EntryInput($title, $slug, $status, $values, $publishedAt);
    }

    /**
     * The stored field values of an entry (for keep-omitted-on-PATCH) —
     * non-relation values from the JSON column, relations from their table.
     *
     * @param array<string,mixed> $row a hydrated entry row
     * @return array<string,mixed>
     */
    private function existingValues(Collection $collection, array $row): array
    {
        $data   = is_array($row['data'] ?? null) ? $row['data'] : [];
        $values = [];
        foreach ($collection->fields as $field) {
            $values[$field->handle] = $field->type === 'relation'
                ? $this->relations->targets((int) $row['id'], $field->id)
                : ($data[$field->handle] ?? null);
        }
        return $values;
    }

    /** A scope predicate for EntryView: which related collections this token may expand. */
    private function scopeFilter(TokenPrincipal $principal): Closure
    {
        return static fn (string $targetHandle): bool => $principal->can($targetHandle, 'read');
    }

    private function boundPerPage(int $requested): int
    {
        if ($requested < 1) {
            $requested = self::DEFAULT_PER_PAGE;
        }
        return min($requested, self::MAX_PER_PAGE);
    }
}

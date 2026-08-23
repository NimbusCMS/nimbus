<?php

declare(strict_types=1);

namespace Nimbus\Mcp;

use Nimbus\Api\EntryOpContext;
use Nimbus\Api\EntryOperations;
use Nimbus\Api\EntryOpResult;
use Nimbus\Api\EntryOpStatus;
use Nimbus\Api\Precondition;
use Nimbus\Api\TokenPrincipal;
use Nimbus\Content\Collection;
use Nimbus\Content\CollectionRepository;
use Nimbus\Content\Field;
use Nimbus\Content\FieldError;
use Nimbus\Content\FieldTypeRegistry;
use Nimbus\Content\Publication;

/**
 * The MCP content tools — one typed, scope-filtered surface per collection,
 * plus introspection, all executing through the shared {@see EntryOperations}
 * (ADR 0009). Because it delegates to that one service, MCP inherits the exact
 * same authorization, mass-assignment binding, concurrency and auditing as the
 * HTTP API; this class only shapes tools and translates results.
 *
 * Two invariants mirror the API:
 * - **Scope-filtered discovery**: `tools/list` offers only the tools a token's
 *   scopes permit, and calling a tool outside scope is reported as an *unknown*
 *   tool — so a token cannot enumerate collections it may not touch.
 * - **Read-before-write**: `update_*` and `delete_*` require the entry's
 *   `version`, evaluated through the same {@see Precondition} the API uses.
 */
final class ContentToolset implements Toolset
{
    public function __construct(
        private CollectionRepository $collections,
        private FieldTypeRegistry $types,
        private EntryOperations $ops,
    ) {
    }

    /**
     * The tool definitions this token may see — content tools for the
     * collections its scopes permit, plus always-safe introspection.
     *
     * @return list<array<string,mixed>>
     */
    public function definitions(TokenPrincipal $principal): array
    {
        $tools = [$this->listCollectionsDefinition(), $this->describeCollectionDefinition()];

        foreach ($this->collections->all() as $collection) {
            if ($principal->can($collection->handle, 'read')) {
                $tools[] = $this->listDefinition($collection);
                $tools[] = $this->getDefinition($collection);
            }
            if ($principal->can($collection->handle, 'write')) {
                $fields = array_values($this->collections->fields($collection->id));
                $tools[] = $this->createDefinition($collection, $fields);
                $tools[] = $this->updateDefinition($collection, $fields);
                $tools[] = $this->deleteDefinition($collection);
            }
        }

        return $tools;
    }

    /**
     * Execute a content `tools/call`, or return null if the name is not a content
     * tool (so the server can offer it to another toolset).
     *
     * @param array<string,mixed> $args
     * @return array<string,mixed>|null
     */
    public function call(string $name, array $args, TokenPrincipal $principal, EntryOpContext $ctx): ?array
    {
        if ($name === 'list_collections') {
            return $this->callListCollections($principal);
        }
        if ($name === 'describe_collection') {
            return $this->callDescribeCollection($args, $principal);
        }

        [$verb, $handle] = $this->split($name);
        if ($handle === '' || !in_array($verb, ['list', 'get', 'create', 'update', 'delete'], true)) {
            return null; // not a content tool — defer to the next toolset
        }

        return $this->callContent($verb, $handle, $name, $args, $principal, $ctx);
    }

    // --------------------------------------------------------------- execution

    /**
     * @param array<string,mixed> $args
     * @return array<string,mixed>
     */
    private function callContent(string $verb, string $handle, string $name, array $args, TokenPrincipal $principal, EntryOpContext $ctx): array
    {
        // Authorization is decided by the one shared service, not re-implemented
        // here: EntryOperations checks scope-before-existence and audits a denial.
        $result = match ($verb) {
            'list'   => $this->ops->list($principal, $ctx, $handle, $this->intArg($args, 'page', 1), $this->intArg($args, 'per_page', 0)),
            'get'    => $this->ops->get($principal, $ctx, $handle, $this->stringArg($args, 'slug')),
            'create' => $this->ops->create($principal, $ctx, $handle, $this->writePayload($args)),
            'update' => $this->ops->update($principal, $ctx, $handle, $this->stringArg($args, 'slug'), $this->writePayload($args), $this->versionPrecondition($args)),
            'delete' => $this->ops->delete($principal, $ctx, $handle, $this->stringArg($args, 'slug'), $this->versionPrecondition($args)),
            default  => throw new \LogicException("Unhandled content verb \"{$verb}\"."),
        };

        // A tool the token may not use is reported as *unknown*, never
        // "forbidden" — so discovery stays least-privilege and nothing leaks
        // (the denial is already audited inside EntryOperations). An in-scope but
        // absent collection surfaces as a normal not_found result, which is safe:
        // the token was granted that handle.
        if ($result->status === EntryOpStatus::Forbidden) {
            throw new McpError(JsonRpc::INVALID_PARAMS, "Unknown tool \"{$name}\".");
        }

        return $this->render($result);
    }

    /** @return array<string,mixed> */
    private function callListCollections(TokenPrincipal $principal): array
    {
        $readable = [];
        foreach ($this->collections->all() as $collection) {
            if ($principal->can($collection->handle, 'read')) {
                $readable[] = ['handle' => $collection->handle, 'name' => $collection->name];
            }
        }
        return ToolResult::ok(['collections' => $readable]);
    }

    /**
     * @param array<string,mixed> $args
     * @return array<string,mixed>
     */
    private function callDescribeCollection(array $args, TokenPrincipal $principal): array
    {
        $handle     = $this->stringArg($args, 'handle');
        $collection = $this->collections->findByHandle($handle);
        // Non-enumeration: an unreadable or absent collection is indistinguishable.
        if ($collection === null || !$principal->can($handle, 'read')) {
            return ToolResult::error("No collection \"{$handle}\".", 'not_found');
        }

        $fields = [];
        foreach ($this->collections->fields($collection->id) as $field) {
            $fields[] = [
                'handle'   => $field->handle,
                'label'    => $field->label,
                'type'     => $field->type,
                'required' => $field->required,
                'schema'   => $this->types->forDisplay($field->type)->jsonSchema($field),
            ];
        }

        return ToolResult::ok([
            'handle'   => $collection->handle,
            'name'     => $collection->name,
            'version'  => $collection->version,
            'writable' => $principal->can($handle, 'write'),
            'fields'   => $fields,
        ]);
    }

    /**
     * Render a content operation's result as a tool result. A successful read/
     * write returns the data (and, for a single entry, its `version` so the
     * agent can do read-before-write); a failure becomes an `isError` result the
     * agent can read, never a thrown protocol error.
     *
     * @return array<string,mixed>
     */
    private function render(EntryOpResult $result): array
    {
        if ($result->status === EntryOpStatus::Ok) {
            $payload = ['data' => $result->data];
            if ($result->meta !== []) {
                $payload['meta'] = $result->meta;
            }
            if ($result->version !== null) {
                $payload['version'] = $result->version;
            }
            return ToolResult::ok($payload);
        }

        // Ok is handled above, so only the failure statuses remain here.
        $code = match ($result->status) {
            EntryOpStatus::Forbidden            => 'forbidden',
            EntryOpStatus::NotFound             => 'not_found',
            // The top-level code the service assigned (`invalid` or `missing_provider`).
            EntryOpStatus::Invalid              => $result->code !== '' ? $result->code : 'invalid',
            EntryOpStatus::PreconditionRequired => 'precondition_required',
            EntryOpStatus::PreconditionFailed   => 'precondition_failed',
        };
        $message = $result->status === EntryOpStatus::Invalid
            ? ($result->message !== '' ? $result->message : 'The entry could not be saved.')
            : $result->message;

        // Per-field errors as structured {code, message} so an agent can branch.
        $fields = array_map(static fn (FieldError $e): array => $e->toArray(), $result->errors);

        return ToolResult::error($message, $code, $fields);
    }

    // ------------------------------------------------------------- definitions

    /** @return array<string,mixed> */
    private function listDefinition(Collection $collection): array
    {
        return $this->tool(
            "list_{$collection->handle}",
            "List published entries in \"{$collection->name}\", newest first.",
            [
                'type'       => 'object',
                'properties' => [
                    'page'     => ['type' => 'integer', 'minimum' => 1, 'description' => 'Page number (from 1).'],
                    'per_page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => EntryOperations::MAX_PER_PAGE, 'description' => 'Entries per page.'],
                ],
            ],
        );
    }

    /** @return array<string,mixed> */
    private function getDefinition(Collection $collection): array
    {
        return $this->tool(
            "get_{$collection->handle}",
            "Get one published entry from \"{$collection->name}\" by slug. Returns its current version for later edits.",
            [
                'type'       => 'object',
                'required'   => ['slug'],
                'properties' => ['slug' => ['type' => 'string', 'description' => 'The entry slug.']],
            ],
        );
    }

    /**
     * @param list<Field> $fields
     * @return array<string,mixed>
     */
    private function createDefinition(Collection $collection, array $fields): array
    {
        return $this->tool(
            "create_{$collection->handle}",
            "Create an entry in \"{$collection->name}\".",
            [
                'type'       => 'object',
                'required'   => ['title'],
                'properties' => [
                    'title'        => ['type' => 'string'],
                    'slug'         => ['type' => 'string', 'description' => 'Optional; derived from the title when omitted.'],
                    'status'       => $this->statusSchema(),
                    'published_at' => ['type' => 'string', 'description' => 'ISO 8601; schedules a publish when in the future.'],
                    'fields'       => $this->fieldsSchema($fields),
                ],
            ],
        );
    }

    /**
     * @param list<Field> $fields
     * @return array<string,mixed>
     */
    private function updateDefinition(Collection $collection, array $fields): array
    {
        return $this->tool(
            "update_{$collection->handle}",
            "Update an entry in \"{$collection->name}\". Requires the entry's current version (read it first with get_{$collection->handle}); omitted fields keep their stored value.",
            [
                'type'       => 'object',
                'required'   => ['slug', 'version'],
                'properties' => [
                    'slug'         => ['type' => 'string', 'description' => 'The entry to update.'],
                    'version'      => ['type' => 'integer', 'description' => 'The version you last read; the write is rejected if the entry changed since.'],
                    'title'        => ['type' => 'string'],
                    'status'       => $this->statusSchema(),
                    'published_at' => ['type' => 'string'],
                    'fields'       => $this->fieldsSchema($fields),
                ],
            ],
        );
    }

    /** @return array<string,mixed> */
    private function deleteDefinition(Collection $collection): array
    {
        return $this->tool(
            "delete_{$collection->handle}",
            "Delete an entry from \"{$collection->name}\". Requires the entry's current version.",
            [
                'type'       => 'object',
                'required'   => ['slug', 'version'],
                'properties' => [
                    'slug'    => ['type' => 'string'],
                    'version' => ['type' => 'integer', 'description' => 'The version you last read.'],
                ],
            ],
        );
    }

    /** @return array<string,mixed> */
    private function listCollectionsDefinition(): array
    {
        return $this->tool(
            'list_collections',
            'List the content collections this token can read, with their handles.',
            ['type' => 'object', 'properties' => new \stdClass()],
        );
    }

    /** @return array<string,mixed> */
    private function describeCollectionDefinition(): array
    {
        return $this->tool(
            'describe_collection',
            'Describe a collection: its fields, their types and JSON schema, and whether this token may write it.',
            [
                'type'       => 'object',
                'required'   => ['handle'],
                'properties' => ['handle' => ['type' => 'string']],
            ],
        );
    }

    /**
     * @param list<Field> $fields
     * @return array<string,mixed>
     */
    private function fieldsSchema(array $fields): array
    {
        $properties = [];
        foreach ($fields as $field) {
            $properties[$field->handle] = $this->types->forDisplay($field->type)->jsonSchema($field);
        }
        return ['type' => 'object', 'description' => "The entry's field values, by handle.", 'properties' => $properties === [] ? new \stdClass() : $properties];
    }

    /** @return array<string,mixed> */
    private function statusSchema(): array
    {
        return ['type' => 'string', 'enum' => Publication::storedStatuses(), 'description' => 'Publication status.'];
    }

    /**
     * @param array<string,mixed> $inputSchema
     * @return array<string,mixed>
     */
    private function tool(string $name, string $description, array $inputSchema): array
    {
        return ['name' => $name, 'description' => $description, 'inputSchema' => $inputSchema];
    }

    // ------------------------------------------------------------------- parse

    /**
     * Split a content tool name into its verb and collection handle on the first
     * underscore. Verbs carry no underscore, so a handle that contains one (or
     * hyphens) is preserved intact.
     *
     * @return array{0:string,1:string}
     */
    private function split(string $name): array
    {
        $at = strpos($name, '_');
        if ($at === false) {
            return [$name, ''];
        }
        return [substr($name, 0, $at), substr($name, $at + 1)];
    }

    /**
     * Assemble the write payload EntryOperations expects. `slug` is intentionally
     * omitted — for update it identifies the entry rather than renaming it, so
     * the stored slug is kept (PATCH semantics).
     *
     * @param array<string,mixed> $args
     * @return array<string,mixed>
     */
    private function writePayload(array $args): array
    {
        $payload = [];
        foreach (['title', 'status', 'published_at'] as $key) {
            if (array_key_exists($key, $args)) {
                $payload[$key] = $args[$key];
            }
        }
        if (is_array($args['fields'] ?? null)) {
            $payload['fields'] = $args['fields'];
        }
        return $payload;
    }

    /** @param array<string,mixed> $args */
    private function versionPrecondition(array $args): Precondition
    {
        return Precondition::version(array_key_exists('version', $args) && is_numeric($args['version']) ? (int) $args['version'] : null);
    }

    /** @param array<string,mixed> $args */
    private function intArg(array $args, string $key, int $default): int
    {
        return isset($args[$key]) && is_numeric($args[$key]) ? (int) $args[$key] : $default;
    }

    /** @param array<string,mixed> $args */
    private function stringArg(array $args, string $key): string
    {
        return is_string($args[$key] ?? null) ? $args[$key] : '';
    }
}

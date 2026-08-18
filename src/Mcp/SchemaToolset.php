<?php

declare(strict_types=1);

namespace Nimbus\Mcp;

use Nimbus\Api\EntryOpContext;
use Nimbus\Api\TokenPrincipal;
use Nimbus\Content\Collection;
use Nimbus\Content\CollectionRepository;
use Nimbus\Content\CollectionService;
use Nimbus\Content\DuplicateHandle;
use Nimbus\Content\Field;
use Nimbus\Content\FieldTypeRegistry;
use Nimbus\Support\CoreEvents;
use Nimbus\Support\EventDispatcher;
use Nimbus\Support\Str;

/**
 * The MCP schema tools (ADR 0009, Slice 4) — structural management of content
 * types, gated on the `schema:write` capability. This is where an agent stops
 * *filling* the CMS and starts *shaping* it, so it is the highest structural
 * privilege in the product and every action is guarded and audited.
 *
 * It reuses the admin's own {@see CollectionService} (one transactional write
 * path for a collection + its fields), never touching tables directly, so MCP
 * schema edits get the same validation and integrity as the admin. Field-level
 * tools read the current fields, mutate, and re-sync — safer than a blind
 * replace — while `set_fields` offers the deliberate full replace for power use.
 *
 * Safety rails: unknown field types are rejected with the valid set; the handle
 * is immutable (renaming would orphan scopes + API paths); `delete_collection`
 * is irreversible and requires `confirm: true`, surfacing the entry count it
 * would destroy; every write emits `api.management_written` for the audit trail.
 */
final class SchemaToolset implements Toolset
{
    /** The management capability this toolset is gated on. */
    private const CAPABILITY = 'schema';

    /** @var list<string> the fixed names this toolset owns */
    private const TOOLS = ['create_collection', 'update_collection', 'add_field', 'remove_field', 'set_fields', 'delete_collection'];

    public function __construct(
        private CollectionRepository $collections,
        private CollectionService $service,
        private FieldTypeRegistry $types,
        private EventDispatcher $events,
    ) {
    }

    /** @return list<array<string,mixed>> */
    public function definitions(TokenPrincipal $principal): array
    {
        if (!$principal->can(self::CAPABILITY, 'write')) {
            return [];
        }
        return [
            $this->createCollectionDefinition(),
            $this->updateCollectionDefinition(),
            $this->addFieldDefinition(),
            $this->removeFieldDefinition(),
            $this->setFieldsDefinition(),
            $this->deleteCollectionDefinition(),
        ];
    }

    /**
     * @param array<string,mixed> $args
     * @return array<string,mixed>|null
     */
    public function call(string $name, array $args, TokenPrincipal $principal, EntryOpContext $ctx): ?array
    {
        if (!in_array($name, self::TOOLS, true)) {
            return null; // not a schema tool — defer to the next toolset
        }
        if (!$principal->can(self::CAPABILITY, 'write')) {
            // Non-enumeration + audit parity with the content path: record the
            // denial, then report the tool as unknown.
            $this->events->emitBestEffort(CoreEvents::API_ACCESS_DENIED, [
                'token_id'   => $principal->tokenId,
                'token_name' => $principal->name,
                'resource'   => self::CAPABILITY,
                'action'     => 'write',
                'ip'         => $ctx->ip,
                'path'       => $ctx->path,
                'at'         => date('Y-m-d H:i:s'),
            ]);
            throw new McpError(JsonRpc::INVALID_PARAMS, "Unknown tool \"{$name}\".");
        }

        return match ($name) {
            'create_collection' => $this->createCollection($args, $principal, $ctx),
            'update_collection' => $this->updateCollection($args, $principal, $ctx),
            'add_field'         => $this->addField($args, $principal, $ctx),
            'remove_field'      => $this->removeField($args, $principal, $ctx),
            'set_fields'        => $this->setFields($args, $principal, $ctx),
            'delete_collection' => $this->deleteCollection($args, $principal, $ctx),
        };
    }

    // --------------------------------------------------------------- execution

    /**
     * @param array<string,mixed> $args
     * @return array<string,mixed>
     */
    private function createCollection(array $args, TokenPrincipal $principal, EntryOpContext $ctx): array
    {
        $handle = Str::handle($this->str($args, 'handle'));
        $name   = trim($this->str($args, 'name'));
        if ($handle === '' || $name === '') {
            return ToolResult::error('A collection needs a non-empty handle and name.', 'invalid');
        }

        $errors = [];
        $fields = $this->fieldDefs(is_array($args['fields'] ?? null) ? $args['fields'] : [], $errors);
        if ($errors !== []) {
            return ToolResult::error('One or more fields are invalid.', 'invalid', $errors);
        }

        try {
            $id = $this->service->create($handle, $name, $this->icon($args), trim($this->str($args, 'description')), $this->defaultOptions(), $fields);
        } catch (DuplicateHandle) {
            return ToolResult::error("A collection with handle \"{$handle}\" already exists.", 'invalid');
        }

        $this->announce($principal, $ctx, 'create_collection', $handle);
        return $this->collectionResult($id, 'created');
    }

    /**
     * @param array<string,mixed> $args
     * @return array<string,mixed>
     */
    private function updateCollection(array $args, TokenPrincipal $principal, EntryOpContext $ctx): array
    {
        $collection = $this->collections->findByHandle($this->str($args, 'handle'));
        if ($collection === null) {
            return $this->noCollection($this->str($args, 'handle'));
        }

        // Metadata only — the field set is preserved. The handle is not editable.
        $this->service->update(
            $collection->id,
            array_key_exists('name', $args) ? trim($this->str($args, 'name')) : $collection->name,
            array_key_exists('icon', $args) ? $this->icon($args) : $collection->icon,
            array_key_exists('description', $args) ? trim($this->str($args, 'description')) : $collection->description,
            $collection->options,
            $this->currentFieldDefs($collection),
        );

        $this->announce($principal, $ctx, 'update_collection', $collection->handle);
        return $this->collectionResult($collection->id, 'updated');
    }

    /**
     * @param array<string,mixed> $args
     * @return array<string,mixed>
     */
    private function addField(array $args, TokenPrincipal $principal, EntryOpContext $ctx): array
    {
        $collection = $this->collections->findByHandle($this->str($args, 'handle'));
        if ($collection === null) {
            return $this->noCollection($this->str($args, 'handle'));
        }

        $errors = [];
        $new    = $this->fieldDef(is_array($args['field'] ?? null) ? $args['field'] : [], $errors, 'field');
        if ($new === null) {
            return ToolResult::error('The field is invalid.', 'invalid', $errors);
        }

        $defs = $this->currentFieldDefs($collection);
        foreach ($defs as $def) {
            if ($def['handle'] === $new['handle']) {
                return ToolResult::error("A field \"{$new['handle']}\" already exists; use set_fields to change it.", 'invalid');
            }
        }
        $defs[] = $new;

        $this->service->update($collection->id, $collection->name, $collection->icon, $collection->description, $collection->options, $defs);
        $this->announce($principal, $ctx, 'add_field', "{$collection->handle}.{$new['handle']}");
        return $this->collectionResult($collection->id, 'updated');
    }

    /**
     * @param array<string,mixed> $args
     * @return array<string,mixed>
     */
    private function removeField(array $args, TokenPrincipal $principal, EntryOpContext $ctx): array
    {
        $collection = $this->collections->findByHandle($this->str($args, 'handle'));
        if ($collection === null) {
            return $this->noCollection($this->str($args, 'handle'));
        }
        $target = $this->str($args, 'field_handle');

        $defs = $this->currentFieldDefs($collection);
        $kept = array_values(array_filter($defs, static fn (array $d): bool => $d['handle'] !== $target));
        if (count($kept) === count($defs)) {
            return ToolResult::error("No field \"{$target}\" in \"{$collection->handle}\".", 'not_found');
        }

        $this->service->update($collection->id, $collection->name, $collection->icon, $collection->description, $collection->options, $kept);
        $this->announce($principal, $ctx, 'remove_field', "{$collection->handle}.{$target}");
        return $this->collectionResult($collection->id, 'updated');
    }

    /**
     * @param array<string,mixed> $args
     * @return array<string,mixed>
     */
    private function setFields(array $args, TokenPrincipal $principal, EntryOpContext $ctx): array
    {
        $collection = $this->collections->findByHandle($this->str($args, 'handle'));
        if ($collection === null) {
            return $this->noCollection($this->str($args, 'handle'));
        }

        $errors = [];
        $fields = $this->fieldDefs(is_array($args['fields'] ?? null) ? $args['fields'] : [], $errors);
        if ($errors !== []) {
            return ToolResult::error('One or more fields are invalid.', 'invalid', $errors);
        }

        // Deliberate full replace — fields not present here are removed, dropping
        // their stored values.
        $this->service->update($collection->id, $collection->name, $collection->icon, $collection->description, $collection->options, $fields);
        $this->announce($principal, $ctx, 'set_fields', $collection->handle);
        return $this->collectionResult($collection->id, 'updated');
    }

    /**
     * @param array<string,mixed> $args
     * @return array<string,mixed>
     */
    private function deleteCollection(array $args, TokenPrincipal $principal, EntryOpContext $ctx): array
    {
        $collection = $this->collections->findByHandle($this->str($args, 'handle'));
        if ($collection === null) {
            return $this->noCollection($this->str($args, 'handle'));
        }

        $entries = $this->collections->entryCount($collection->id);
        if (($args['confirm'] ?? null) !== true) {
            // Irreversible + drops content: make the agent acknowledge the blast
            // radius. The entry count is surfaced so it can decide.
            return ToolResult::error(
                "Deleting \"{$collection->handle}\" permanently removes the collection and its {$entries} entr" . ($entries === 1 ? 'y' : 'ies') . '. Pass confirm:true to proceed.',
                'confirmation_required',
                ['entries' => (string) $entries],
            );
        }

        $this->service->delete($collection->id);
        $this->announce($principal, $ctx, 'delete_collection', $collection->handle);
        return ToolResult::ok(['deleted' => $collection->handle, 'entries_removed' => $entries]);
    }

    // ----------------------------------------------------------------- helpers

    /**
     * Build validated field definitions from a list of field arguments,
     * collecting per-field errors by handle/index.
     *
     * @param array<int|string,mixed> $rows
     * @param array<string,string>    $errors
     * @return list<array{handle:string,label:string,type:string,required:bool,options:array<string,mixed>}>
     */
    private function fieldDefs(array $rows, array &$errors): array
    {
        $defs = [];
        $i    = 0;
        foreach ($rows as $row) {
            $def = $this->fieldDef(is_array($row) ? $row : [], $errors, "fields[{$i}]");
            if ($def !== null) {
                $defs[] = $def;
            }
            $i++;
        }
        return $defs;
    }

    /**
     * Validate one field argument into a definition, or null (recording why).
     *
     * @param array<string,mixed>  $row
     * @param array<string,string> $errors
     * @return array{handle:string,label:string,type:string,required:bool,options:array<string,mixed>}|null
     */
    private function fieldDef(array $row, array &$errors, string $key): ?array
    {
        $label = trim($this->str($row, 'label'));
        $type  = $this->str($row, 'type');
        if ($label === '') {
            $errors[$key] = 'A field needs a label.';
            return null;
        }
        if (!$this->types->has($type)) {
            $errors[$key] = "Unknown field type \"{$type}\". Valid types: " . implode(', ', array_keys($this->types->choices())) . '.';
            return null;
        }
        $handle = Str::handle($this->str($row, 'handle') !== '' ? $this->str($row, 'handle') : $label);
        if ($handle === '') {
            $errors[$key] = 'A field needs a handle (or a label to derive one from).';
            return null;
        }

        return [
            'handle'   => $handle,
            'label'    => $label,
            'type'     => $type,
            'required' => (bool) ($row['required'] ?? false),
            'options'  => is_array($row['options'] ?? null) ? $row['options'] : [],
        ];
    }

    /**
     * The current fields of a collection as definitions (for preserve/mutate).
     *
     * @return list<array{handle:string,label:string,type:string,required:bool,options:array<string,mixed>}>
     */
    private function currentFieldDefs(Collection $collection): array
    {
        return array_map(
            static fn (Field $f): array => ['handle' => $f->handle, 'label' => $f->label, 'type' => $f->type, 'required' => $f->required, 'options' => $f->options],
            $collection->fields,
        );
    }

    /**
     * The success payload for a collection write: its fresh shape + version.
     *
     * @return array<string,mixed>
     */
    private function collectionResult(int $id, string $action): array
    {
        $collection = $this->collections->find($id);
        if ($collection === null) {
            return ToolResult::error('The collection could not be read back.', 'invalid');
        }
        return ToolResult::ok([
            'action'     => $action,
            'collection' => [
                'handle'  => $collection->handle,
                'name'    => $collection->name,
                'version' => $collection->version,
                'fields'  => array_map(static fn (Field $f): array => ['handle' => $f->handle, 'type' => $f->type, 'required' => $f->required], $collection->fields),
            ],
        ]);
    }

    /** @return array<string,mixed> */
    private function noCollection(string $handle): array
    {
        return ToolResult::error("No collection \"{$handle}\".", 'not_found');
    }

    private function announce(TokenPrincipal $principal, EntryOpContext $ctx, string $action, string $target): void
    {
        $this->events->emitBestEffort(CoreEvents::API_MANAGEMENT_WRITTEN, [
            'token_id'   => $principal->tokenId,
            'token_name' => $principal->name,
            'capability' => self::CAPABILITY,
            'action'     => $action,
            'target'     => $target,
            'ip'         => $ctx->ip,
            'path'       => $ctx->path,
            'at'         => date('Y-m-d H:i:s'),
        ]);
    }

    /** @return array<string,mixed> the standard options for an agent-created collection */
    private function defaultOptions(): array
    {
        return ['kind' => 'collection', 'permissions' => ['manage' => []]];
    }

    /** @param array<string,mixed> $args */
    private function icon(array $args): string
    {
        $icon = trim($this->str($args, 'icon'));
        return $icon !== '' ? mb_substr($icon, 0, 4) : '❑';
    }

    /** @param array<string,mixed> $args */
    private function str(array $args, string $key): string
    {
        return is_string($args[$key] ?? null) ? $args[$key] : '';
    }

    // ------------------------------------------------------------- definitions

    /** @return array<string,mixed> */
    private function createCollectionDefinition(): array
    {
        return $this->tool('create_collection', 'Create a new collection (content type).', [
            'type'       => 'object',
            'required'   => ['handle', 'name'],
            'properties' => [
                'handle'      => ['type' => 'string', 'description' => 'URL-safe unique id (lowercase, hyphens). Cannot be changed later.'],
                'name'        => ['type' => 'string'],
                'icon'        => ['type' => 'string', 'description' => 'An emoji or short label.'],
                'description' => ['type' => 'string'],
                'fields'      => ['type' => 'array', 'description' => 'Initial fields.', 'items' => $this->fieldObjectSchema()],
            ],
        ]);
    }

    /** @return array<string,mixed> */
    private function updateCollectionDefinition(): array
    {
        return $this->tool('update_collection', "Update a collection's name, icon or description (its handle and fields are untouched).", [
            'type'       => 'object',
            'required'   => ['handle'],
            'properties' => [
                'handle'      => ['type' => 'string', 'description' => 'The collection to update.'],
                'name'        => ['type' => 'string'],
                'icon'        => ['type' => 'string'],
                'description' => ['type' => 'string'],
            ],
        ]);
    }

    /** @return array<string,mixed> */
    private function addFieldDefinition(): array
    {
        return $this->tool('add_field', 'Add one field to a collection.', [
            'type'       => 'object',
            'required'   => ['handle', 'field'],
            'properties' => [
                'handle' => ['type' => 'string', 'description' => 'The collection to add the field to.'],
                'field'  => $this->fieldObjectSchema(),
            ],
        ]);
    }

    /** @return array<string,mixed> */
    private function removeFieldDefinition(): array
    {
        return $this->tool('remove_field', "Remove one field from a collection. This drops that field's stored values.", [
            'type'       => 'object',
            'required'   => ['handle', 'field_handle'],
            'properties' => [
                'handle'       => ['type' => 'string'],
                'field_handle' => ['type' => 'string', 'description' => 'The handle of the field to remove.'],
            ],
        ]);
    }

    /** @return array<string,mixed> */
    private function setFieldsDefinition(): array
    {
        return $this->tool('set_fields', 'Replace a collection\'s ENTIRE field set. Fields not listed are removed, dropping their stored values. Use add_field / remove_field for incremental changes.', [
            'type'       => 'object',
            'required'   => ['handle', 'fields'],
            'properties' => [
                'handle' => ['type' => 'string'],
                'fields' => ['type' => 'array', 'items' => $this->fieldObjectSchema()],
            ],
        ]);
    }

    /** @return array<string,mixed> */
    private function deleteCollectionDefinition(): array
    {
        return $this->tool('delete_collection', 'Permanently delete a collection and all its entries. Irreversible; requires confirm:true (call once without it to see how many entries would be destroyed).', [
            'type'       => 'object',
            'required'   => ['handle'],
            'properties' => [
                'handle'  => ['type' => 'string'],
                'confirm' => ['type' => 'boolean', 'description' => 'Must be true to actually delete.'],
            ],
        ]);
    }

    /** @return array<string,mixed> the JSON schema for a field argument */
    private function fieldObjectSchema(): array
    {
        return [
            'type'       => 'object',
            'required'   => ['label', 'type'],
            'properties' => [
                'handle'   => ['type' => 'string', 'description' => 'Optional; derived from the label when omitted.'],
                'label'    => ['type' => 'string'],
                'type'     => ['type' => 'string', 'enum' => array_keys($this->types->choices()), 'description' => 'A registered field type.'],
                'required' => ['type' => 'boolean'],
                'options'  => ['type' => 'object', 'description' => 'Type-specific options (e.g. a select\'s choices, a relation\'s target collection).'],
            ],
        ];
    }

    /**
     * @param array<string,mixed> $inputSchema
     * @return array<string,mixed>
     */
    private function tool(string $name, string $description, array $inputSchema): array
    {
        return ['name' => $name, 'description' => $description, 'inputSchema' => $inputSchema];
    }
}

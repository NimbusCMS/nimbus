<?php

declare(strict_types=1);

namespace Nimbus\Api;

use Nimbus\Content\Collection;
use Nimbus\Content\CollectionRepository;
use Nimbus\Content\Field;
use Nimbus\Content\FieldTypeRegistry;
use Nimbus\Content\Publication;
use Nimbus\Support\Config;

/**
 * Builds an OpenAPI 3.0 document for the `/api/v1` surface (ADR 0008).
 *
 * Generated, not hand-written: the content model is user-defined, so the paths
 * and schemas come from the live collections + their fields. Each field's shape
 * is whatever its type reports from jsonSchema(), so the spec cannot drift from
 * what toApi() actually serializes. Returns a PHP array; the endpoint and the CLI
 * JSON-encode it.
 */
final class OpenApiGenerator
{
    private string $title;

    public function __construct(
        private CollectionRepository $collections,
        private FieldTypeRegistry $types,
        ?string $title = null,
    ) {
        // The site title (settings store) titles the spec's `info`. Falls back to
        // the config default when a caller does not resolve it.
        $this->title = $title ?? Config::appName();
    }

    /** @return array<string,mixed> */
    public function generate(): array
    {
        $paths   = [];
        $schemas = $this->baseSchemas();

        foreach ($this->collections->all() as $collection) {
            $fields = $this->collections->fields($collection->id);
            $name   = $this->schemaName($collection->handle);

            $schemas['Entry_' . $name]      = $this->entrySchema($fields);
            $schemas['EntryWrite_' . $name] = $this->writeSchema($fields);
            $paths += $this->collectionPaths($collection, $name);
        }

        return [
            'openapi' => '3.0.3',
            'info'    => [
                'title'       => $this->title . ' API',
                'version'     => 'v1',
                'description' => 'Generated from the live content model. Read + write; bearer-token auth with per-collection read/write scopes; optimistic concurrency via ETag/If-Match.',
            ],
            'servers'    => [['url' => rtrim(Config::appUrl(), '/') . '/api/v1']],
            'security'   => [['bearerAuth' => []]],
            'paths'      => $paths,
            'components' => [
                'securitySchemes' => ['bearerAuth' => ['type' => 'http', 'scheme' => 'bearer']],
                'parameters'      => $this->baseParameters(),
                'schemas'         => $schemas,
            ],
        ];
    }

    /**
     * @param Field[] $fields
     *
     * @return array<string,mixed>
     */
    private function entrySchema(array $fields): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'id'           => ['type' => 'integer'],
                'slug'         => ['type' => 'string'],
                'title'        => ['type' => 'string'],
                'published_at' => ['type' => 'string', 'format' => 'date-time', 'nullable' => true],
                'fields'       => $this->fieldsSchema($fields),
            ],
        ];
    }

    /**
     * @param Field[] $fields
     *
     * @return array<string,mixed>
     */
    private function writeSchema(array $fields): array
    {
        return [
            'type'       => 'object',
            'required'   => ['title'],
            'properties' => [
                'title'  => ['type' => 'string'],
                'slug'   => ['type' => 'string'],
                'status' => ['type' => 'string', 'enum' => [Publication::DRAFT, Publication::PUBLISHED, Publication::ARCHIVED]],
                'fields' => $this->fieldsSchema($fields),
            ],
        ];
    }

    /**
     * @param Field[] $fields
     *
     * @return array<string,mixed>
     */
    private function fieldsSchema(array $fields): array
    {
        $properties = [];
        foreach ($fields as $field) {
            $properties[$field->handle] = $this->types->forDisplay($field->type)->jsonSchema($field);
        }

        return ['type' => 'object', 'properties' => $properties];
    }

    /** @return array<string,mixed> */
    private function collectionPaths(Collection $collection, string $name): array
    {
        $handle   = $collection->handle;
        $entryRef = ['$ref' => '#/components/schemas/Entry_' . $name];
        $writeRef = ['$ref' => '#/components/schemas/EntryWrite_' . $name];
        $list     = '/collections/' . $handle . '/entries';

        return [
            $list => [
                'get' => [
                    'summary'    => "List live {$handle} entries",
                    'parameters' => [['$ref' => '#/components/parameters/page'], ['$ref' => '#/components/parameters/perPage']],
                    'responses'  => [
                        '200' => ['description' => 'A page of entries', 'content' => ['application/json' => ['schema' => [
                            'type'       => 'object',
                            'properties' => ['data' => ['type' => 'array', 'items' => $entryRef], 'meta' => ['$ref' => '#/components/schemas/EntryMeta']],
                        ]]]],
                        '403' => $this->errorResponse('Out of read scope'),
                    ],
                ],
                'post' => [
                    'summary'     => "Create a {$handle} entry",
                    'requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => $writeRef]]],
                    'responses'   => [
                        '201' => ['description' => 'Created', 'headers' => $this->etagHeader() + ['Location' => ['schema' => ['type' => 'string']]], 'content' => ['application/json' => ['schema' => $this->dataWrap($entryRef)]]],
                        '403' => $this->errorResponse('Out of write scope'),
                        '422' => $this->errorResponse('Validation failed'),
                    ],
                ],
            ],
            $list . '/{slug}' => [
                'parameters' => [['name' => 'slug', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']]],
                'get'        => [
                    'summary'   => "Get a {$handle} entry",
                    'responses' => [
                        '200' => ['description' => 'The entry', 'headers' => $this->etagHeader(), 'content' => ['application/json' => ['schema' => $this->dataWrap($entryRef)]]],
                        '404' => $this->errorResponse('Not found'),
                    ],
                ],
                'patch' => [
                    'summary'     => "Update a {$handle} entry",
                    'parameters'  => [['$ref' => '#/components/parameters/IfMatch']],
                    'requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => $writeRef]]],
                    'responses'   => [
                        '200' => ['description' => 'Updated', 'headers' => $this->etagHeader(), 'content' => ['application/json' => ['schema' => $this->dataWrap($entryRef)]]],
                        '403' => $this->errorResponse('Out of write scope'),
                        '404' => $this->errorResponse('Not found'),
                        '412' => $this->errorResponse('If-Match is stale'),
                        '422' => $this->errorResponse('Validation failed'),
                        '428' => $this->errorResponse('If-Match is required'),
                    ],
                ],
                'delete' => [
                    'summary'    => "Delete a {$handle} entry",
                    'parameters' => [['$ref' => '#/components/parameters/IfMatch']],
                    'responses'  => [
                        '204' => ['description' => 'Deleted'],
                        '403' => $this->errorResponse('Out of write scope'),
                        '404' => $this->errorResponse('Not found'),
                        '412' => $this->errorResponse('If-Match is stale'),
                        '428' => $this->errorResponse('If-Match is required'),
                    ],
                ],
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function baseSchemas(): array
    {
        return [
            'Error' => [
                'type'       => 'object',
                'properties' => ['error' => ['type' => 'object', 'properties' => [
                    'status'  => ['type' => 'integer'],
                    'code'    => ['type' => 'string'],
                    'message' => ['type' => 'string'],
                    'fields'  => ['type' => 'object', 'additionalProperties' => ['type' => 'string'], 'nullable' => true],
                ]]],
            ],
            'EntryMeta' => [
                'type'       => 'object',
                'properties' => [
                    'page'        => ['type' => 'integer'],
                    'per_page'    => ['type' => 'integer'],
                    'total'       => ['type' => 'integer'],
                    'total_pages' => ['type' => 'integer'],
                ],
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function baseParameters(): array
    {
        return [
            'IfMatch' => ['name' => 'If-Match', 'in' => 'header', 'required' => true, 'schema' => ['type' => 'string'], 'description' => "The entry's current ETag; required on write."],
            'page'    => ['name' => 'page', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1]],
            'perPage' => ['name' => 'per_page', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50]],
        ];
    }

    /**
     * @param array<string,mixed> $ref
     *
     * @return array<string,mixed>
     */
    private function dataWrap(array $ref): array
    {
        return ['type' => 'object', 'properties' => ['data' => $ref]];
    }

    /** @return array<string,mixed> */
    private function errorResponse(string $description): array
    {
        return ['description' => $description, 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Error']]]];
    }

    /** @return array<string,mixed> */
    private function etagHeader(): array
    {
        return ['ETag' => ['schema' => ['type' => 'string'], 'description' => 'The entry version, for If-Match on a later write.']];
    }

    /** A safe OpenAPI component-name from a collection handle. */
    private function schemaName(string $handle): string
    {
        return (string) preg_replace('/[^A-Za-z0-9_]/', '_', $handle);
    }
}

<?php

declare(strict_types=1);

namespace Nimbus\Tests\Integration;

use Nimbus\Api\OpenApiGenerator;
use Nimbus\Content\CollectionRepository;
use Nimbus\Content\CollectionService;
use Nimbus\Content\FieldTypeRegistry;

/**
 * The generated OpenAPI document — built from the live collections + fields.
 */
final class OpenApiGeneratorTest extends IntegrationTestCase
{
    private function generate(): string
    {
        $repo = new CollectionRepository($this->db);
        (new CollectionService($this->db, $repo))->create(
            'posts',
            'Posts',
            '#',
            '',
            ['kind' => 'collection', 'permissions' => ['manage' => ['editor']]],
            [
                ['handle' => 'body', 'label' => 'Body', 'type' => 'textarea', 'required' => false, 'options' => []],
                ['handle' => 'rank', 'label' => 'Rank', 'type' => 'number', 'required' => false, 'options' => []],
            ],
        );

        return json_encode((new OpenApiGenerator($repo, new FieldTypeRegistry()))->generate(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    public function test_it_is_a_secured_openapi_3_document(): void
    {
        $json = $this->generate();

        self::assertJson($json);
        self::assertStringContainsString('"openapi":"3.0.3"', $json);
        self::assertStringContainsString('"bearerAuth"', $json, 'the bearer security scheme');
        self::assertStringContainsString('"security":[{"bearerAuth":[]}]', $json, 'auth is required globally');
        self::assertStringContainsString('"Error"', $json, 'the shared error schema');
    }

    public function test_each_collection_gets_read_and_write_paths(): void
    {
        $json = $this->generate();

        self::assertStringContainsString('"/collections/posts/entries"', $json);
        self::assertStringContainsString('"/collections/posts/entries/{slug}"', $json);
        // the write verbs + their concurrency/validation codes are documented
        self::assertStringContainsString('"428"', $json, 'If-Match required');
        self::assertStringContainsString('"412"', $json, 'If-Match stale');
        self::assertStringContainsString('"422"', $json, 'validation');
    }

    public function test_the_entry_schema_reflects_each_field_type(): void
    {
        $json = $this->generate();

        self::assertStringContainsString('"Entry_posts"', $json);
        self::assertStringContainsString('"EntryWrite_posts"', $json);
        self::assertStringContainsString('"body":{"type":"string"}', $json, 'textarea → string');
        self::assertStringContainsString('"rank":{"type":"number"}', $json, 'number → number');
    }

    public function test_the_info_title_reflects_the_resolved_site_title(): void
    {
        $repo = new CollectionRepository($this->db);
        $doc  = (new OpenApiGenerator($repo, new FieldTypeRegistry(), 'Danmat Studio'))->generate();

        self::assertSame('Danmat Studio API', $doc['info']['title']);
    }
}

<?php

declare(strict_types=1);

namespace Nimbus\Tests\Integration;

use Nimbus\Api\OpenApiGenerator;
use Nimbus\Api\TokenPrincipal;
use Nimbus\Content\CollectionRepository;
use Nimbus\Content\CollectionService;
use Nimbus\Content\FieldTypeRegistry;

/**
 * The generated OpenAPI document — built from the live collections + fields.
 * `generateFull()` (CLI) describes everything; `generateFor($token)` (the HTTP
 * endpoint) is scoped to the token so the spec can't enumerate collections the
 * token gets 403==404 on elsewhere (ADR 0008 amended by Slice B; API-3).
 */
final class OpenApiGeneratorTest extends IntegrationTestCase
{
    private function makePosts(): CollectionRepository
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
        return $repo;
    }

    private function makeCollection(CollectionRepository $repo, string $handle): void
    {
        (new CollectionService($this->db, $repo))->create(
            $handle,
            ucfirst($handle),
            '#',
            '',
            ['kind' => 'collection', 'permissions' => ['manage' => ['editor']]],
            [['handle' => 'body', 'label' => 'Body', 'type' => 'textarea', 'required' => false, 'options' => []]],
        );
    }

    private function generate(): string
    {
        return json_encode((new OpenApiGenerator($this->makePosts(), new FieldTypeRegistry()))->generateFull(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /** @param list<string> $scopes */
    private function scopedDoc(CollectionRepository $repo, array $scopes): string
    {
        $principal = new TokenPrincipal(1, 'T', $scopes);
        return json_encode((new OpenApiGenerator($repo, new FieldTypeRegistry()))->generateFor($principal), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
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
        $doc  = (new OpenApiGenerator($repo, new FieldTypeRegistry(), 'Danmat Studio'))->generateFull();

        self::assertSame('Danmat Studio API', $doc['info']['title']);
    }

    // ------------------------------------------------------- scope filtering

    public function test_a_scoped_spec_omits_collections_out_of_read_scope(): void
    {
        $repo = $this->makePosts();
        $this->makeCollection($repo, 'secret');

        // A posts:read token learns nothing about `secret` — not the path, not the
        // schema name (the handle), not a $ref. Assert on the WHOLE document.
        $json = $this->scopedDoc($repo, ['posts:read']);
        self::assertStringContainsString('/collections/posts/entries', $json);
        self::assertStringNotContainsString('secret', $json, 'no path, schema name, or ref leaks the collection');
    }

    public function test_a_read_only_token_gets_no_write_operations(): void
    {
        $repo = $this->makePosts();
        $json = $this->scopedDoc($repo, ['posts:read']);

        self::assertStringContainsString('"Entry_posts"', $json);
        self::assertStringNotContainsString('EntryWrite_posts', $json, 'no write schema for a read-only token');
        self::assertStringNotContainsString('"post"', $json, 'no create operation');
        self::assertStringNotContainsString('"patch"', $json, 'no update operation');
        self::assertStringNotContainsString('"delete"', $json, 'no delete operation');
    }

    public function test_a_write_token_gets_the_write_operations(): void
    {
        $repo = $this->makePosts();
        $json = $this->scopedDoc($repo, ['posts:write']);

        self::assertStringContainsString('EntryWrite_posts', $json);
        self::assertStringContainsString('"post":', $json);
        self::assertStringContainsString('"delete":', $json, 'write implies the full write path set');
    }

    public function test_admin_and_wildcard_read_see_every_collection(): void
    {
        $repo = $this->makePosts();
        $this->makeCollection($repo, 'secret');

        foreach (['admin', '*:read'] as $scope) {
            $json = $this->scopedDoc($repo, [$scope]);
            self::assertStringContainsString('/collections/posts/entries', $json, $scope);
            self::assertStringContainsString('/collections/secret/entries', $json, $scope);
        }
    }

    public function test_a_token_with_no_content_scope_gets_a_valid_but_empty_paths_document(): void
    {
        $repo = $this->makePosts();
        $doc  = (new OpenApiGenerator($repo, new FieldTypeRegistry()))->generateFor(new TokenPrincipal(1, 'T', ['users:write']));

        self::assertSame([], $doc['paths'], 'no readable collection → no paths');
        self::assertArrayNotHasKey('Entry_posts', $doc['components']['schemas']);
        self::assertSame('3.0.3', $doc['openapi'], 'still a valid document');
    }
}

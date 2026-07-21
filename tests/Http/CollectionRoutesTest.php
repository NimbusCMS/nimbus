<?php

declare(strict_types=1);

namespace Nimbus\Tests\Http;

use Nimbus\Content\CollectionRepository;

final class CollectionRoutesTest extends HttpTestCase
{
    private CollectionRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new CollectionRepository($this->db);
    }

    /**
     * The field-builder payload, shaped the way PHP parses `fields[0][label]`
     * out of a real form body.
     *
     * @param array<int,string> ...$rows [label, handle, type?]
     * @return array<string,array<int,array<string,string>>>
     */
    private function fields(array ...$rows): array
    {
        return ['fields' => array_map(
            static fn (array $r): array => ['label' => $r[0], 'handle' => $r[1], 'type' => $r[2] ?? 'text'],
            $rows,
        )];
    }

    // ------------------------------------------------------------- reading

    public function test_index_returns_a_200_html_response(): void
    {
        $this->actingAs('admin');
        $this->makeCollection('posts');

        $response = $this->assertOkHtml($this->get('/admin/collections'));

        self::assertStringContainsString('Posts', $response->body);
    }

    public function test_new_collection_form_renders_for_an_admin(): void
    {
        $this->actingAs('admin');

        $response = $this->assertOkHtml($this->get('/admin/collections/new'));

        self::assertStringContainsString('name="handle"', $response->body);
    }

    public function test_editing_a_missing_collection_redirects(): void
    {
        $this->actingAs('admin');

        $this->assertRedirects($this->get('/admin/collections/9999/edit'), '/admin/collections');
    }

    // --------------------------------------------------------- permissions

    public function test_non_admin_cannot_reach_the_collection_form(): void
    {
        $this->actingAs('editor', 'editor@test.local');

        $this->assertRedirects($this->get('/admin/collections/new'), '/admin/collections');
    }

    public function test_non_admin_cannot_create_a_collection(): void
    {
        $this->actingAs('editor', 'editor@test.local');

        $response = $this->post('/admin/collections', ['name' => 'Sneaky', 'handle' => 'sneaky']);

        $this->assertRedirects($response, '/admin/collections');
        self::assertNull($this->repo->findByHandle('sneaky'), 'nothing may be created');
    }

    public function test_non_admin_cannot_update_a_collection(): void
    {
        $collection = $this->makeCollection('posts');
        $this->actingAs('editor', 'editor@test.local');

        $this->post("/admin/collections/{$collection->id}", ['name' => 'Renamed']);

        self::assertSame('Posts', $this->repo->find($collection->id)->name);
    }

    public function test_non_admin_cannot_delete_a_collection(): void
    {
        $collection = $this->makeCollection('posts');
        $this->actingAs('editor', 'editor@test.local');

        $this->post("/admin/collections/{$collection->id}/delete");

        self::assertNotNull($this->repo->find($collection->id), 'the collection must survive');
    }

    public function test_creating_without_csrf_is_rejected(): void
    {
        $this->actingAs('admin');

        $this->postWithoutCsrf('/admin/collections', ['name' => 'Posts', 'handle' => 'posts']);

        self::assertNull($this->repo->findByHandle('posts'));
    }

    public function test_deleting_without_csrf_is_rejected(): void
    {
        $collection = $this->makeCollection('posts');
        $this->actingAs('admin');

        $this->postWithoutCsrf("/admin/collections/{$collection->id}/delete");

        self::assertNotNull($this->repo->find($collection->id));
    }

    // ------------------------------------------------------------ writing

    public function test_create_persists_the_collection_and_its_fields_together(): void
    {
        $this->actingAs('admin');

        $response = $this->post('/admin/collections', [
            'name' => 'Posts', 'handle' => 'posts', 'kind' => 'collection', 'icon' => '❑', 'description' => 'Blog posts',
        ] + $this->fields(['Body', 'body', 'textarea'], ['Qty', 'qty', 'number']));

        $this->assertRedirects($response, '/admin/collections?msg=created');

        // One transaction: the collection and both fields, or neither.
        $collection = $this->repo->findByHandle('posts');
        self::assertNotNull($collection);
        self::assertSame('Blog posts', $collection->description);
        self::assertCount(2, $collection->fields);
        self::assertSame(['body', 'qty'], array_map(static fn ($f) => $f->handle, $collection->fields));
    }

    public function test_a_failed_field_write_rolls_back_the_whole_collection(): void
    {
        $this->actingAs('admin');

        // A label past VARCHAR(120) fails the field insert mid-transaction.
        $response = $this->post('/admin/collections', [
            'name' => 'Broken', 'handle' => 'broken', 'kind' => 'collection',
        ] + $this->fields([str_repeat('x', 300), 'wide']));

        self::assertSame(500, $response->status, 'an unexpected DB error surfaces as a generic 500');
        self::assertNull($this->repo->findByHandle('broken'), 'the collection must not be left behind');
    }

    public function test_update_changes_the_collection(): void
    {
        $collection = $this->makeCollection('posts');
        $this->actingAs('admin');

        $response = $this->post("/admin/collections/{$collection->id}", [
            'name' => 'Articles', 'kind' => 'collection', 'icon' => '★', 'description' => 'Renamed',
        ]);

        $this->assertRedirects($response, '/admin/collections?msg=updated');
        $updated = $this->repo->find($collection->id);
        self::assertSame('Articles', $updated->name);
        self::assertSame('★', $updated->icon);
    }

    public function test_delete_removes_the_collection(): void
    {
        $collection = $this->makeCollection('posts');
        $this->actingAs('admin');

        $response = $this->post("/admin/collections/{$collection->id}/delete");

        $this->assertRedirects($response, '/admin/collections?msg=deleted');
        self::assertNull($this->repo->find($collection->id));
    }

    // --------------------------------------------------------- validation

    public function test_validation_failure_re_renders_with_the_submitted_values(): void
    {
        $this->actingAs('admin');

        $response = $this->post('/admin/collections', [
            'name' => '', 'handle' => '', 'icon' => '★', 'description' => 'a description worth keeping',
        ] + $this->fields(['Tagline', 'tagline']));

        self::assertSame(200, $response->status, 'the form is re-rendered, not redirected');
        self::assertStringContainsString('Name is required', $response->body);
        // The work the user did must survive the round trip.
        self::assertStringContainsString('a description worth keeping', $response->body);
        self::assertStringContainsString('value="★"', $response->body);
        self::assertStringContainsString('value="Tagline"', $response->body);
    }

    public function test_duplicate_handle_returns_a_useful_error_and_keeps_the_submission(): void
    {
        $this->makeCollection('posts');
        $this->actingAs('admin');

        $response = $this->post('/admin/collections', [
            'name' => 'Second Posts', 'handle' => 'posts', 'kind' => 'single', 'description' => 'my careful description',
        ] + $this->fields(['Tagline', 'tagline']));

        self::assertSame(200, $response->status);
        self::assertStringContainsString('already taken', $response->body);
        self::assertStringContainsString('value="Second Posts"', $response->body);
        self::assertStringContainsString('my careful description', $response->body);
        self::assertStringContainsString('value="Tagline"', $response->body);
    }

    public function test_duplicate_handle_does_not_disturb_the_existing_collection(): void
    {
        $original = $this->makeCollection('posts', [
            ['handle' => 'body', 'label' => 'Body', 'type' => 'text', 'required' => false, 'options' => []],
        ]);
        $this->actingAs('admin');

        $this->post('/admin/collections', [
            'name' => 'Impostor', 'handle' => 'posts',
        ] + $this->fields(['Other', 'other']));

        $after = $this->repo->find($original->id);
        self::assertSame('Posts', $after->name);
        self::assertCount(1, $after->fields);
        self::assertSame('body', $after->fields[0]->handle);
        self::assertCount(1, $this->repo->all(), 'no second collection was created');
    }

    public function test_update_with_a_blank_name_re_renders_instead_of_silently_redirecting(): void
    {
        $collection = $this->makeCollection('posts');
        $this->actingAs('admin');

        $response = $this->post("/admin/collections/{$collection->id}", ['name' => '']);

        self::assertSame(200, $response->status);
        self::assertStringContainsString('Name is required', $response->body);
        self::assertSame('Posts', $this->repo->find($collection->id)->name, 'nothing was written');
    }
}

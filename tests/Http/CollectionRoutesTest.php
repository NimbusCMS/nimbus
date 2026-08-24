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
        return ['fields' => array_values(array_map(
            static fn (array $r): array => ['label' => $r[0], 'handle' => $r[1], 'type' => $r[2] ?? 'text'],
            $rows,
        ))];
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

    public function test_a_relation_field_with_a_bogus_target_is_rejected(): void
    {
        // ADMIN-14a: the target was stored raw — a nonexistent handle yielded a
        // dead relation + empty picker. Now the whole create is rejected.
        $this->actingAs('admin');

        $response = $this->post('/admin/collections', [
            'name' => 'Books', 'handle' => 'books', 'kind' => 'collection',
            'fields' => [['label' => 'Author', 'handle' => 'author', 'type' => 'relation', 'target' => 'ghosts']],
        ]);

        self::assertSame(200, $response->status, 're-renders with the row error, no redirect');
        self::assertStringContainsString('does not exist', $response->body);
        self::assertNull($this->repo->findByHandle('books'), 'a bogus relation target blocks the whole create');
    }

    public function test_a_relation_field_with_a_real_target_saves(): void
    {
        $this->actingAs('admin');
        $this->makeCollection('authors');

        $response = $this->post('/admin/collections', [
            'name' => 'Books', 'handle' => 'books', 'kind' => 'collection',
            'fields' => [['label' => 'Author', 'handle' => 'author', 'type' => 'relation', 'target' => 'authors']],
        ]);

        $this->assertRedirects($response, '/admin/collections?msg=created');
        $books = $this->repo->findByHandle('books');
        self::assertNotNull($books);
        self::assertSame('authors', $books->fields[0]->option('target', ''));
    }

    public function test_a_relation_field_with_a_blank_target_is_rejected(): void
    {
        $this->actingAs('admin');

        $response = $this->post('/admin/collections', [
            'name' => 'Books', 'handle' => 'books', 'kind' => 'collection',
            'fields' => [['label' => 'Author', 'handle' => 'author', 'type' => 'relation']],
        ]);

        self::assertSame(200, $response->status);
        self::assertStringContainsString('Choose a target collection', $response->body);
        self::assertNull($this->repo->findByHandle('books'));
    }

    public function test_a_collection_handle_that_collides_with_a_permission_name_is_rejected(): void
    {
        // FU-4: a collection named after a management capability would be judged
        // under management authz rules. Rejected at create on the admin form.
        $this->actingAs('admin');
        foreach (['media', 'users', 'tokens', 'settings', 'roles', 'schema', 'admin'] as $reserved) {
            $response = $this->post('/admin/collections', ['name' => ucfirst($reserved), 'handle' => $reserved]);
            self::assertSame(200, $response->status, "{$reserved} is rejected + re-rendered");
            self::assertStringContainsString('reserved', $response->body);
            self::assertNull($this->repo->findByHandle($reserved), "no {$reserved} collection created");
        }
    }

    public function test_the_reserved_handle_check_is_normalization_insensitive(): void
    {
        // "Media" Str::handle-normalizes to "media" — the check must see the
        // normalized handle, not the raw input (else the guard is bypassable).
        $this->actingAs('admin');
        $response = $this->post('/admin/collections', ['name' => 'Press', 'handle' => 'Media']);

        self::assertSame(200, $response->status);
        self::assertNull($this->repo->findByHandle('media'));
    }

    public function test_a_field_handle_that_collides_with_a_built_in_entry_key_is_rejected(): void
    {
        // FU-6: a field named title/slug/published_at collides with the entry's
        // own keys in the flat error map. Rejected as a NEW field at create.
        $this->actingAs('admin');
        foreach (['title', 'slug', 'published_at'] as $reserved) {
            $handle   = 'posts_' . $reserved;
            $response = $this->post('/admin/collections', ['name' => 'C ' . $reserved, 'handle' => $handle]
                + $this->fields([ucfirst($reserved), $reserved, 'text']));
            self::assertSame(200, $response->status);
            self::assertStringContainsString('reserved', $response->body);
            self::assertNull($this->repo->findByHandle($handle));
        }
    }

    public function test_a_pre_existing_collection_with_a_reserved_handle_still_edits(): void
    {
        // Grandfathering: the collection-handle check is create-only, so a
        // collection named `media` from before this guard (seeded past the
        // service here) still saves on edit — the handle is immutable anyway.
        $this->actingAs('admin');
        $c = $this->makeCollection('legacymedia');
        $this->db->execute('UPDATE nb_collections SET handle = :h WHERE id = :id', ['h' => 'media', 'id' => (int) $c->id]);

        $response = $this->post('/admin/collections/' . (int) $c->id, ['name' => 'Media Renamed', 'handle' => 'media']);

        $this->assertRedirects($response, '/admin/collections?msg=updated');
        self::assertSame('Media Renamed', $this->repo->find((int) $c->id)->name);
    }

    public function test_a_pre_existing_reserved_field_survives_an_edit(): void
    {
        // Grandfathering: a stored field named `title` is not renamed out from
        // under its values (new-only check) — an edit that keeps it and adds a
        // normal field saves.
        $this->actingAs('admin');
        $c = $this->makeCollection('legacy');
        $this->db->execute(
            "INSERT INTO nb_fields (collection_id, handle, label, type, required, options, sort, created_at)
             VALUES (:c, 'title', 'Title', 'text', 0, NULL, 0, NOW())",
            ['c' => (int) $c->id],
        );

        $response = $this->post('/admin/collections/' . (int) $c->id, ['name' => 'Legacy', 'handle' => 'legacy']
            + $this->fields(['Title', 'title', 'text'], ['Body', 'body', 'textarea']));

        $this->assertRedirects($response, '/admin/collections?msg=updated');
        self::assertSame(['title', 'body'], array_map(static fn ($f) => $f->handle, $this->repo->find((int) $c->id)->fields));
    }

    public function test_an_over_long_field_label_is_caught_before_the_write(): void
    {
        $this->actingAs('admin');

        // A label past VARCHAR(120) is now rejected at validation (Slice G / ADMIN-8),
        // so the form re-renders with a friendly error and the collection is never
        // created — better than the old mid-transaction 500 + rollback.
        $response = $this->post('/admin/collections', [
            'name' => 'Broken', 'handle' => 'broken', 'kind' => 'collection',
        ] + $this->fields([str_repeat('x', 300), 'wide']));

        self::assertSame(200, $response->status, 'a friendly re-render, not a 500');
        self::assertStringContainsString('Field label must be', $response->body);
        self::assertNull($this->repo->findByHandle('broken'), 'the collection is not created');
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

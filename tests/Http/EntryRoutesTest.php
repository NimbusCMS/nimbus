<?php

declare(strict_types=1);

namespace Nimbus\Tests\Http;

use Nimbus\Content\Collection;
use Nimbus\Content\EntryService;

final class EntryRoutesTest extends HttpTestCase
{
    /**
     * @param array<string,mixed> $options
     * @return array{handle:string,label:string,type:string,required:bool,options:array<string,mixed>}
     */
    private function field(string $handle, string $type = 'text', bool $required = false, array $options = []): array
    {
        return ['handle' => $handle, 'label' => ucfirst($handle), 'type' => $type, 'required' => $required, 'options' => $options];
    }

    private function seedEntry(Collection $collection, string $title): int
    {
        $this->post("/admin/collections/{$collection->handle}/entries", ['title' => $title, 'status' => 'draft']);
        return (int) $this->db->selectOne(
            'SELECT id FROM nb_entries WHERE collection_id = :c ORDER BY id DESC LIMIT 1',
            ['c' => $collection->id]
        )['id'];
    }

    // ------------------------------------------------------------ creating

    public function test_create_redirects_after_a_successful_save(): void
    {
        $collection = $this->makeCollection('posts', [$this->field('body', 'textarea')]);
        $this->actingAs('admin');

        $response = $this->post('/admin/collections/posts/entries', [
            'title' => 'Hello', 'status' => 'published', 'f' => ['body' => 'the body'],
        ]);

        $this->assertRedirects($response, '/admin/collections/posts/entries?msg=created');
        $row = $this->db->selectOne('SELECT * FROM nb_entries WHERE collection_id = :c', ['c' => $collection->id]);
        self::assertSame('Hello', $row['title']);
        self::assertSame('hello', $row['slug']);
        self::assertSame('published', $row['status']);
        self::assertStringContainsString('the body', $row['data']);
    }

    public function test_entry_list_returns_200_html(): void
    {
        $collection = $this->makeCollection('posts');
        $this->actingAs('admin');
        $this->seedEntry($collection, 'Listed');

        $response = $this->assertOkHtml($this->get('/admin/collections/posts/entries'));

        self::assertStringContainsString('Listed', $response->body);
    }

    public function test_update_changes_the_entry(): void
    {
        $collection = $this->makeCollection('posts');
        $this->actingAs('admin');
        $id = $this->seedEntry($collection, 'Before');

        $response = $this->post("/admin/collections/posts/entries/{$id}", ['title' => 'After', 'status' => 'draft']);

        $this->assertRedirects($response, '/admin/collections/posts/entries?msg=updated');
        self::assertSame('After', $this->db->selectOne('SELECT title FROM nb_entries WHERE id = :i', ['i' => $id])['title']);
    }

    public function test_delete_removes_the_entry(): void
    {
        $collection = $this->makeCollection('posts');
        $this->actingAs('admin');
        $id = $this->seedEntry($collection, 'Doomed');

        $response = $this->post("/admin/collections/posts/entries/{$id}/delete");

        $this->assertRedirects($response, '/admin/collections/posts/entries?msg=deleted');
        self::assertSame(0, $this->entryCount($collection->id));
    }

    // ---------------------------------------------------------- validation

    public function test_missing_title_re_renders_the_form_with_the_error(): void
    {
        $collection = $this->makeCollection('posts', [$this->field('body', 'textarea')]);
        $this->actingAs('admin');

        $response = $this->post('/admin/collections/posts/entries', [
            'title' => '', 'status' => 'draft', 'f' => ['body' => 'typed something here'],
        ]);

        self::assertSame(200, $response->status, 'a failed save re-renders rather than redirecting');
        self::assertStringContainsString('Title is required', $response->body);
        self::assertStringContainsString('typed something here', $response->body, 'submitted values are preserved');
        self::assertSame(0, $this->entryCount($collection->id));
    }

    public function test_optional_invalid_number_is_rejected(): void
    {
        $collection = $this->makeCollection('products', [$this->field('qty', 'number', required: false)]);
        $this->actingAs('admin');

        $response = $this->post('/admin/collections/products/entries', [
            'title' => 'Widget', 'status' => 'draft', 'f' => ['qty' => 'not-a-number'],
        ]);

        self::assertSame(200, $response->status);
        self::assertStringContainsString('valid number', $response->body);
        self::assertSame(0, $this->entryCount($collection->id), 'an invalid number must not be silently stored as blank');
    }

    public function test_optional_blank_number_is_accepted(): void
    {
        $collection = $this->makeCollection('products', [$this->field('qty', 'number', required: false)]);
        $this->actingAs('admin');

        $response = $this->post('/admin/collections/products/entries', [
            'title' => 'Widget', 'status' => 'draft', 'f' => ['qty' => ''],
        ]);

        $this->assertRedirectsTo($response, '/admin/collections/products/entries');
        self::assertSame(1, $this->entryCount($collection->id));
    }

    public function test_zero_satisfies_a_required_number(): void
    {
        $collection = $this->makeCollection('products', [$this->field('qty', 'number', required: true)]);
        $this->actingAs('admin');

        $response = $this->post('/admin/collections/products/entries', [
            'title' => 'Free', 'status' => 'draft', 'f' => ['qty' => '0'],
        ]);

        $this->assertRedirectsTo($response, '/admin/collections/products/entries');
        $data = json_decode(
            $this->db->selectOne('SELECT data FROM nb_entries WHERE collection_id = :c', ['c' => $collection->id])['data'],
            true,
        );
        self::assertSame(0, $data['qty'], 'stored as integer zero, not "" or null');
    }

    // ------------------------------------------------- cross-collection ids

    public function test_an_entry_from_another_collection_cannot_be_edited(): void
    {
        $posts = $this->makeCollection('posts');
        $this->makeCollection('pages');
        $this->actingAs('admin');
        $id = $this->seedEntry($posts, 'Belongs to posts');

        // Same id, wrong collection in the URL.
        $response = $this->get("/admin/collections/pages/entries/{$id}/edit");

        $this->assertRedirects($response, '/admin/collections/pages/entries');
    }

    public function test_an_entry_from_another_collection_cannot_be_updated(): void
    {
        $posts = $this->makeCollection('posts');
        $this->makeCollection('pages');
        $this->actingAs('admin');
        $id = $this->seedEntry($posts, 'Belongs to posts');

        $this->post("/admin/collections/pages/entries/{$id}", ['title' => 'Hijacked', 'status' => 'draft']);

        self::assertSame(
            'Belongs to posts',
            $this->db->selectOne('SELECT title FROM nb_entries WHERE id = :i', ['i' => $id])['title'],
        );
    }

    public function test_an_entry_from_another_collection_cannot_be_deleted(): void
    {
        $posts = $this->makeCollection('posts');
        $this->makeCollection('pages');
        $this->actingAs('admin');
        $id = $this->seedEntry($posts, 'Belongs to posts');

        $response = $this->post("/admin/collections/pages/entries/{$id}/delete");

        $this->assertRedirects($response, '/admin/collections/pages/entries');
        self::assertSame(1, $this->entryCount($posts->id), 'the entry must survive');
    }

    // ------------------------------------------------------------ managing

    public function test_a_user_without_manage_permission_cannot_create_entries(): void
    {
        $collection = $this->makeCollection('posts', [], ['kind' => 'collection', 'permissions' => ['manage' => []]]);
        $this->actingAs('editor', 'editor@test.local');

        $response = $this->post('/admin/collections/posts/entries', ['title' => 'Nope', 'status' => 'draft']);

        $this->assertRedirects($response, '/admin/collections/posts/entries');
        self::assertSame(0, $this->entryCount($collection->id));
    }

    public function test_a_permitted_role_can_create_entries(): void
    {
        $collection = $this->makeCollection('posts', [], ['kind' => 'collection', 'permissions' => ['manage' => ['editor']]]);
        $this->actingAs('editor', 'editor@test.local');

        $response = $this->post('/admin/collections/posts/entries', ['title' => 'Allowed', 'status' => 'draft']);

        $this->assertRedirectsTo($response, '/admin/collections/posts/entries');
        self::assertSame(1, $this->entryCount($collection->id));
    }

    public function test_writing_an_entry_without_csrf_is_rejected(): void
    {
        $collection = $this->makeCollection('posts');
        $this->actingAs('admin');

        $this->postWithoutCsrf('/admin/collections/posts/entries', ['title' => 'Forged', 'status' => 'draft']);

        self::assertSame(0, $this->entryCount($collection->id));
    }

    public function test_unknown_collection_handle_redirects(): void
    {
        $this->actingAs('admin');

        $this->assertRedirects($this->get('/admin/collections/nosuchthing/entries'), '/admin/collections');
    }

    // ----------------------------------------------------------- singleton

    public function test_singleton_save_never_creates_a_second_entry(): void
    {
        $collection = $this->makeCollection('site_settings', [$this->field('tagline')], ['kind' => 'single', 'permissions' => ['manage' => []]]);
        $this->actingAs('admin');

        $this->post('/admin/collections/site_settings/entries', ['title' => '', 'status' => 'draft', 'f' => ['tagline' => 'first']]);
        $this->post('/admin/collections/site_settings/entries', ['title' => '', 'status' => 'draft', 'f' => ['tagline' => 'second']]);
        $this->post('/admin/collections/site_settings/entries', ['title' => '', 'status' => 'draft', 'f' => ['tagline' => 'third']]);

        self::assertSame(1, $this->entryCount($collection->id));
        $row = $this->db->selectOne('SELECT slug, data FROM nb_entries WHERE collection_id = :c', ['c' => $collection->id]);
        self::assertSame(EntryService::SINGLETON_SLUG, $row['slug']);
        self::assertStringContainsString('third', $row['data'], 'later saves update the one row');
    }

    public function test_singleton_list_renders_the_single_entry_form(): void
    {
        $this->makeCollection('site_settings', [$this->field('tagline')], ['kind' => 'single', 'permissions' => ['manage' => []]]);
        $this->actingAs('admin');

        $response = $this->assertOkHtml($this->get('/admin/collections/site_settings/entries'));

        self::assertStringContainsString('name="f[tagline]"', $response->body);
    }

    // ------------------------------------------------------- slug conflicts

    public function test_duplicate_slug_conflict_is_resolved_not_raised(): void
    {
        $collection = $this->makeCollection('posts');
        $this->actingAs('admin');

        $this->post('/admin/collections/posts/entries', ['title' => 'Same Title', 'status' => 'draft']);
        $response = $this->post('/admin/collections/posts/entries', ['title' => 'Same Title', 'status' => 'draft']);

        $this->assertRedirectsTo($response, '/admin/collections/posts/entries');
        $slugs = array_column(
            $this->db->select('SELECT slug FROM nb_entries WHERE collection_id = :c ORDER BY id', ['c' => $collection->id]),
            'slug',
        );
        self::assertSame(['same-title', 'same-title-2'], $slugs);
    }

    // ----------------------------------------------------------- relations

    public function test_relation_writes_go_through_the_entry_service(): void
    {
        $categories = $this->makeCollection('categories');
        $this->actingAs('admin');
        $catId = $this->seedEntry($categories, 'News');

        $posts = $this->makeCollection('posts', [
            $this->field('categories', 'relation', options: ['target' => 'categories', 'multiple' => true]),
        ]);
        $this->rebuildRouter();

        $response = $this->post('/admin/collections/posts/entries', [
            'title' => 'Linked', 'status' => 'draft', 'f' => ['categories' => [(string) $catId]],
        ]);

        $this->assertRedirectsTo($response, '/admin/collections/posts/entries');

        // Relations live in their own table, written inside the same transaction.
        $entryId = (int) $this->db->selectOne('SELECT id FROM nb_entries WHERE collection_id = :c', ['c' => $posts->id])['id'];
        $rows    = $this->db->select('SELECT to_entry_id FROM nb_relations WHERE from_entry_id = :e', ['e' => $entryId]);
        self::assertCount(1, $rows);
        self::assertSame($catId, (int) $rows[0]['to_entry_id']);
    }

    public function test_relations_are_replaced_on_update_not_appended(): void
    {
        $categories = $this->makeCollection('categories');
        $this->actingAs('admin');
        $first  = $this->seedEntry($categories, 'News');
        $second = $this->seedEntry($categories, 'Reviews');

        $posts = $this->makeCollection('posts', [
            $this->field('categories', 'relation', options: ['target' => 'categories', 'multiple' => true]),
        ]);
        $this->rebuildRouter();

        $this->post('/admin/collections/posts/entries', [
            'title' => 'Linked', 'status' => 'draft', 'f' => ['categories' => [(string) $first]],
        ]);
        $entryId = (int) $this->db->selectOne('SELECT id FROM nb_entries WHERE collection_id = :c', ['c' => $posts->id])['id'];

        $this->post("/admin/collections/posts/entries/{$entryId}", [
            'title' => 'Linked', 'status' => 'draft', 'f' => ['categories' => [(string) $second]],
        ]);

        $rows = $this->db->select('SELECT to_entry_id FROM nb_relations WHERE from_entry_id = :e', ['e' => $entryId]);
        self::assertCount(1, $rows, 'sync replaces, it does not accumulate');
        self::assertSame($second, (int) $rows[0]['to_entry_id']);
    }
}

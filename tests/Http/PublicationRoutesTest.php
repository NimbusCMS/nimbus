<?php

declare(strict_types=1);

namespace Nimbus\Tests\Http;

use Nimbus\Content\Publication;

/**
 * The publication lifecycle driven through the real admin boundary: setting a
 * status and a publish time on the entry form, and the publish/unpublish quick
 * actions. The domain rules themselves live in PublicationTest and
 * EntryServiceTest; this checks the HTTP wiring on top of them.
 */
final class PublicationRoutesTest extends HttpTestCase
{
    /** @return array{status:string,published_at:?string} */
    private function row(int $id): array
    {
        $r = $this->db->selectOne('SELECT status, published_at FROM nb_entries WHERE id = :i', ['i' => $id]);
        return ['status' => (string) $r['status'], 'published_at' => $r['published_at']];
    }

    private function idOf(int $collectionId): int
    {
        return (int) $this->db->selectOne(
            'SELECT id FROM nb_entries WHERE collection_id = :c ORDER BY id DESC LIMIT 1',
            ['c' => $collectionId],
        )['id'];
    }

    // --------------------------------------------------------------- the form

    public function test_creating_as_published_goes_live_now(): void
    {
        $c = $this->makeCollection('posts');
        $this->actingAs('admin');

        $this->post('/admin/collections/posts/entries', ['title' => 'Live', 'status' => 'published']);

        $row = $this->row($this->idOf($c->id));
        self::assertSame('published', $row['status']);
        self::assertNotNull($row['published_at']);
        self::assertTrue(Publication::isLive($row['status'], $row['published_at']));
    }

    public function test_a_future_publish_time_schedules_rather_than_publishes(): void
    {
        $c = $this->makeCollection('posts');
        $this->actingAs('admin');
        $future = (new \DateTimeImmutable('+3 days'))->format('Y-m-d\TH:i');

        $this->post('/admin/collections/posts/entries', [
            'title' => 'Soon', 'status' => 'published', 'published_at' => $future,
        ]);

        $row = $this->row($this->idOf($c->id));
        self::assertSame('published', $row['status']);
        self::assertFalse(Publication::isLive($row['status'], $row['published_at']), 'a future time is not live');
        self::assertSame(Publication::STATE_SCHEDULED, Publication::state($row['status'], $row['published_at']));
    }

    public function test_archived_status_is_accepted(): void
    {
        $c = $this->makeCollection('posts');
        $this->actingAs('admin');

        $this->post('/admin/collections/posts/entries', ['title' => 'Old', 'status' => 'archived']);

        self::assertSame('archived', $this->row($this->idOf($c->id))['status']);
    }

    public function test_an_unknown_status_falls_back_to_draft(): void
    {
        $c = $this->makeCollection('posts');
        $this->actingAs('admin');

        $this->post('/admin/collections/posts/entries', ['title' => 'Weird', 'status' => 'scheduled']);

        // "scheduled" is not a storable status — it must not slip through.
        self::assertSame('draft', $this->row($this->idOf($c->id))['status']);
    }

    public function test_the_edit_form_shows_the_scheduled_hint_and_datetime(): void
    {
        $c = $this->makeCollection('posts');
        $this->actingAs('admin');
        $future = (new \DateTimeImmutable('+2 days'))->format('Y-m-d\TH:i');
        $this->post('/admin/collections/posts/entries', ['title' => 'Soon', 'status' => 'published', 'published_at' => $future]);
        $id = $this->idOf($c->id);

        $body = $this->get("/admin/collections/posts/entries/{$id}/edit")->body;

        self::assertStringContainsString('Scheduled', $body);
        self::assertStringContainsString('value="' . $future . '"', $body, 'the publish time round-trips into the form');
    }

    // ------------------------------------------------------- quick actions

    public function test_publish_action_makes_a_draft_live(): void
    {
        $c = $this->makeCollection('posts');
        $this->actingAs('admin');
        $this->post('/admin/collections/posts/entries', ['title' => 'Draft', 'status' => 'draft']);
        $id = $this->idOf($c->id);
        self::assertSame('draft', $this->row($id)['status']);

        $response = $this->post("/admin/collections/posts/entries/{$id}/publish");

        $this->assertRedirects($response, '/admin/collections/posts/entries?msg=published');
        $row = $this->row($id);
        self::assertTrue(Publication::isLive($row['status'], $row['published_at']));
    }

    public function test_unpublish_action_returns_an_entry_to_draft_but_keeps_its_date(): void
    {
        $c = $this->makeCollection('posts');
        $this->actingAs('admin');
        $this->post('/admin/collections/posts/entries', ['title' => 'Live', 'status' => 'published']);
        $id   = $this->idOf($c->id);
        $date = $this->row($id)['published_at'];

        $response = $this->post("/admin/collections/posts/entries/{$id}/unpublish");

        $this->assertRedirects($response, '/admin/collections/posts/entries?msg=unpublished');
        $row = $this->row($id);
        self::assertSame('draft', $row['status']);
        self::assertSame($date, $row['published_at'], 'the publish date is kept for re-publishing');
    }

    public function test_quick_actions_preserve_field_values(): void
    {
        $c = $this->makeCollection('posts', [$this->fieldDef('body', 'textarea')]);
        $this->actingAs('admin');
        $this->post('/admin/collections/posts/entries', [
            'title' => 'Keep', 'status' => 'draft', 'f' => ['body' => 'important text'],
        ]);
        $id = $this->idOf($c->id);

        $this->post("/admin/collections/posts/entries/{$id}/publish");

        $data = json_decode($this->db->selectOne('SELECT data FROM nb_entries WHERE id = :i', ['i' => $id])['data'], true);
        self::assertSame('important text', $data['body'], 'a status flip must not drop field data');
    }

    public function test_quick_actions_require_csrf(): void
    {
        $c = $this->makeCollection('posts');
        $this->actingAs('admin');
        $this->post('/admin/collections/posts/entries', ['title' => 'Draft', 'status' => 'draft']);
        $id = $this->idOf($c->id);

        $this->postWithoutCsrf("/admin/collections/posts/entries/{$id}/publish");

        self::assertSame('draft', $this->row($id)['status'], 'a forged publish must not take effect');
    }

    public function test_a_non_manager_cannot_publish(): void
    {
        $c = $this->makeCollection('posts', [], ['kind' => 'collection', 'permissions' => ['manage' => []]]);
        $this->actingAs('admin');
        $this->post('/admin/collections/posts/entries', ['title' => 'Draft', 'status' => 'draft']);
        $id = $this->idOf($c->id);

        $this->actingAs('editor', 'editor@test.local');
        $this->post("/admin/collections/posts/entries/{$id}/publish");

        self::assertSame('draft', $this->row($id)['status']);
    }

    /** @return array{handle:string,label:string,type:string,required:bool,options:array<string,mixed>} */
    private function fieldDef(string $handle, string $type): array
    {
        return ['handle' => $handle, 'label' => ucfirst($handle), 'type' => $type, 'required' => false, 'options' => []];
    }
}

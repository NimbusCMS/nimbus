<?php

declare(strict_types=1);

namespace Nimbus\Tests\Http;

use Nimbus\Api\ApiTokenRepository;
use Nimbus\Content\Collection;
use Nimbus\Content\EntryView;
use Nimbus\Content\FieldTypeRegistry;
use Nimbus\Content\RelationRepository;
use Nimbus\Http\Request;
use Nimbus\Media\MediaRepository;

/**
 * Relation-value integrity (DATA-1): a relation field's stored links are
 * constrained to its declared target collection at write, and re-checked against
 * the entry's real collection at read — so a `posts` relation can never store or
 * expand a `secret` entry, and retargeting the field can't re-open the leak.
 */
final class RelationIntegrityTest extends HttpTestCase
{
    /**
     * @param array<string,mixed> $options
     * @return array{handle:string,label:string,type:string,required:bool,options:array<string,mixed>}
     */
    private function field(string $handle, string $type = 'text', array $options = []): array
    {
        return ['handle' => $handle, 'label' => ucfirst($handle), 'type' => $type, 'required' => false, 'options' => $options];
    }

    /** Insert a live (published) entry directly, returning its id. */
    private function liveEntry(int $collectionId, string $title): int
    {
        return $this->db->insert(
            'INSERT INTO nb_entries (collection_id, title, slug, status, version, data, published_at, created_at, updated_at)
             VALUES (:c, :t, :s, :st, 1, :d, :p, :ca, :u)',
            ['c' => $collectionId, 't' => $title, 's' => strtolower(str_replace(' ', '-', $title)), 'st' => 'published', 'd' => '{}', 'p' => date('Y-m-d H:i:s', time() - 60), 'ca' => date('Y-m-d H:i:s'), 'u' => date('Y-m-d H:i:s')],
        );
    }

    /** @return list<int> the stored relation target ids for an entry, in order */
    private function storedTargets(int $entryId): array
    {
        return array_values(array_map(
            static fn (array $r): int => (int) $r['to_entry_id'],
            $this->db->select('SELECT to_entry_id FROM nb_relations WHERE from_entry_id = :e ORDER BY sort, id', ['e' => $entryId]),
        ));
    }

    private function postsWithRelationTo(string $target): Collection
    {
        $posts = $this->makeCollection('posts', [$this->field('rel', 'relation', ['target' => $target, 'multiple' => true])]);
        $this->rebuildRouter();
        return $posts;
    }

    private function newestEntryId(int $collectionId): int
    {
        return (int) $this->db->selectOne('SELECT id FROM nb_entries WHERE collection_id = :c ORDER BY id DESC LIMIT 1', ['c' => $collectionId])['id'];
    }

    // --------------------------------------------------------------- write

    public function test_write_drops_ids_outside_the_target_collection(): void
    {
        $cats   = $this->makeCollection('categories');
        $secret = $this->makeCollection('secret');
        $this->actingAs('admin');
        $catId    = $this->liveEntry($cats->id, 'News');
        $secretId = $this->liveEntry($secret->id, 'Confidential');

        $posts = $this->postsWithRelationTo('categories');
        $this->post('/admin/collections/posts/entries', [
            'title' => 'Linked', 'status' => 'draft', 'f' => ['rel' => [(string) $catId, (string) $secretId]],
        ]);

        self::assertSame([$catId], $this->storedTargets($this->newestEntryId($posts->id)), 'the foreign id is dropped, the valid one kept');
    }

    public function test_write_preserves_submitted_order_after_filtering(): void
    {
        $cats = $this->makeCollection('categories');
        $this->actingAs('admin');
        $a = $this->liveEntry($cats->id, 'Alpha');
        $b = $this->liveEntry($cats->id, 'Bravo');
        $foreign = $this->liveEntry($this->makeCollection('other')->id, 'Other');

        $posts = $this->postsWithRelationTo('categories');
        $this->post('/admin/collections/posts/entries', [
            'title' => 'Ordered', 'status' => 'draft', 'f' => ['rel' => [(string) $b, (string) $foreign, (string) $a]],
        ]);

        self::assertSame([$b, $a], $this->storedTargets($this->newestEntryId($posts->id)), 'submitted order preserved, foreign dropped');
    }

    public function test_a_nonexistent_id_is_dropped_not_a_500(): void
    {
        $cats = $this->makeCollection('categories');
        $this->actingAs('admin');
        $catId = $this->liveEntry($cats->id, 'News');

        $posts = $this->postsWithRelationTo('categories');
        // A nonexistent id used to hit the to_entry_id FK → 500; now it drops,
        // indistinguishable from a cross-collection id (no existence oracle).
        $res = $this->post('/admin/collections/posts/entries', [
            'title' => 'Safe', 'status' => 'draft', 'f' => ['rel' => ['999999', (string) $catId]],
        ]);

        self::assertSame(302, $res->status, 'a graceful redirect, never a 500');
        self::assertSame([$catId], $this->storedTargets($this->newestEntryId($posts->id)));
    }

    public function test_an_empty_target_stores_nothing(): void
    {
        $cats = $this->makeCollection('categories');
        $this->actingAs('admin');
        $catId = $this->liveEntry($cats->id, 'News');

        $posts = $this->postsWithRelationTo(''); // misconfigured field: no target
        $this->post('/admin/collections/posts/entries', [
            'title' => 'X', 'status' => 'draft', 'f' => ['rel' => [(string) $catId]],
        ]);

        self::assertSame([], $this->storedTargets($this->newestEntryId($posts->id)), 'no target → nothing stored (fail closed)');
    }

    // ---------------------------------------------------------------- read

    private function view(): EntryView
    {
        return new EntryView(new FieldTypeRegistry(), new RelationRepository($this->db), new MediaRepository($this->db));
    }

    public function test_the_read_gate_neutralizes_a_stored_cross_collection_row(): void
    {
        // Simulate a legacy/retargeted bad row: a link stored directly, pointing at
        // a live entry in a DIFFERENT collection than the field's target.
        $cats   = $this->makeCollection('categories');
        $secret = $this->makeCollection('secret');
        $this->actingAs('admin');
        $catId    = $this->liveEntry($cats->id, 'News');
        $secretId = $this->liveEntry($secret->id, 'TopSecretTitle');

        $posts   = $this->postsWithRelationTo('categories');
        $entryId = $this->liveEntry($posts->id, 'Host');
        $fieldId = (int) $this->db->selectOne('SELECT id FROM nb_fields WHERE collection_id = :c AND handle = :h', ['c' => $posts->id, 'h' => 'rel'])['id'];
        // A valid link and a smuggled cross-collection one.
        (new RelationRepository($this->db))->sync($entryId, $fieldId, [$catId, $secretId]);

        $row = $this->db->selectOne('SELECT * FROM nb_entries WHERE id = :id', ['id' => $entryId]);
        $out = $this->view()->one($posts, $row, null); // public expansion (no scope limit)

        $titles = array_column($out['fields']['rel'], 'title');
        self::assertSame(['News'], $titles, 'only the target-collection row expands; the smuggled secret is filtered out');
    }

    public function test_the_scope_gate_still_hides_a_legitimately_targeted_unreadable_collection(): void
    {
        // A field legitimately targeting `secret`: the collection filter alone
        // would return the secret rows; the canRead gate is what stops a reader
        // without secret:read (retained alongside the integrity filter).
        $secret = $this->makeCollection('secret');
        $this->actingAs('admin');
        $secretId = $this->liveEntry($secret->id, 'TopSecretTitle');

        $posts   = $this->postsWithRelationTo('secret');
        $entryId = $this->liveEntry($posts->id, 'Host');
        $fieldId = (int) $this->db->selectOne('SELECT id FROM nb_fields WHERE collection_id = :c AND handle = :h', ['c' => $posts->id, 'h' => 'rel'])['id'];
        (new RelationRepository($this->db))->sync($entryId, $fieldId, [$secretId]);

        $row = $this->db->selectOne('SELECT * FROM nb_entries WHERE id = :id', ['id' => $entryId]);

        // A reader who cannot read `secret` sees nothing…
        $denied = $this->view()->one($posts, $row, static fn (string $h): bool => $h !== 'secret');
        self::assertSame([], $denied['fields']['rel']);
        // …one who can, sees it (proving the gate, not emptiness).
        $allowed = $this->view()->one($posts, $row, static fn (string $h): bool => true);
        self::assertSame(['TopSecretTitle'], array_column($allowed['fields']['rel'], 'title'));
    }

    public function test_a_same_collection_relation_still_stores_and_expands(): void
    {
        $cats = $this->makeCollection('categories');
        $this->actingAs('admin');
        $catId = $this->liveEntry($cats->id, 'News');

        $posts   = $this->postsWithRelationTo('categories');
        $entryId = $this->liveEntry($posts->id, 'Host');
        $fieldId = (int) $this->db->selectOne('SELECT id FROM nb_fields WHERE collection_id = :c AND handle = :h', ['c' => $posts->id, 'h' => 'rel'])['id'];
        (new RelationRepository($this->db))->sync($entryId, $fieldId, [$catId]);

        $row = $this->db->selectOne('SELECT * FROM nb_entries WHERE id = :id', ['id' => $entryId]);
        $out = $this->view()->one($posts, $row, null);

        self::assertSame(['News'], array_column($out['fields']['rel'], 'title'), 'a legitimate same-collection relation is unaffected');
    }

    public function test_a_scoped_api_token_never_sees_a_cross_collection_target(): void
    {
        // End-to-end: a posts:read+posts:write token, no secret:read; a smuggled
        // cross-collection row. The secret title appears nowhere in the response.
        $cats   = $this->makeCollection('categories');
        $secret = $this->makeCollection('secret');
        $this->actingAs('admin');
        $catId    = $this->liveEntry($cats->id, 'News');
        $secretId = $this->liveEntry($secret->id, 'TopSecretTitle');

        $posts   = $this->postsWithRelationTo('categories');
        $entryId = $this->liveEntry($posts->id, 'Host');
        $fieldId = (int) $this->db->selectOne('SELECT id FROM nb_fields WHERE collection_id = :c AND handle = :h', ['c' => $posts->id, 'h' => 'rel'])['id'];
        (new RelationRepository($this->db))->sync($entryId, $fieldId, [$catId, $secretId]);

        // Can read posts + the legit target categories, but NOT secret. The
        // categories row expands; the smuggled secret row is dropped by the
        // collection filter regardless (it isn't in the declared target).
        $token   = (new ApiTokenRepository($this->db))->create('scoped', ['posts:read', 'categories:read']);
        $server  = ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_AUTHORIZATION' => 'Bearer ' . $token];
        $body    = $this->throughKernel(new Request('GET', '/api/v1/collections/posts/entries/host', [], [], $server, []))->body;

        self::assertStringContainsString('News', $body, 'the valid target expands');
        self::assertStringNotContainsString('TopSecretTitle', $body, 'the smuggled cross-collection target never appears');
    }

    public function test_saving_an_unrelated_field_reaps_a_legacy_bad_row(): void
    {
        // Lazy cleanup: the write filter re-runs on any save (the PATCH/submit echo
        // re-feeds stored ids), so a legacy cross-collection row is dropped even by
        // a save that doesn't touch the relation field.
        $cats   = $this->makeCollection('categories');
        $secret = $this->makeCollection('secret');
        $this->actingAs('admin');
        $catId    = $this->liveEntry($cats->id, 'News');
        $secretId = $this->liveEntry($secret->id, 'Secret');

        $posts = $this->makeCollection('posts', [
            $this->field('rel', 'relation', ['target' => 'categories', 'multiple' => true]),
            $this->field('body', 'textarea'),
        ]);
        $this->rebuildRouter();
        $entryId = $this->liveEntry($posts->id, 'Host');
        $fieldId = (int) $this->db->selectOne('SELECT id FROM nb_fields WHERE collection_id = :c AND handle = :h', ['c' => $posts->id, 'h' => 'rel'])['id'];
        (new RelationRepository($this->db))->sync($entryId, $fieldId, [$catId, $secretId]);

        // Edit the entry, resubmitting the relation as the form would (both current ids).
        $this->post("/admin/collections/posts/entries/{$entryId}", [
            'title' => 'Host', 'status' => 'published', 'f' => ['rel' => [(string) $catId, (string) $secretId], 'body' => 'x'],
        ]);

        self::assertSame([$catId], $this->storedTargets($entryId), 'the legacy cross-collection row is reaped on save');
    }
}

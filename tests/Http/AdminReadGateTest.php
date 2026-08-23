<?php

declare(strict_types=1);

namespace Nimbus\Tests\Http;

use Nimbus\Http\Url;

/**
 * ADR 0011's deny-by-default read gate on the admin (ADMIN-1 / ADMIN-4). A
 * signed-in user browses only the collections they can read; an out-of-scope
 * collection is indistinguishable from a missing one (non-enumeration), matching
 * what the API already enforces. Behaviour-preserving for seeded system roles
 * (admin/editor/author hold `*:read`).
 */
final class AdminReadGateTest extends HttpTestCase
{
    private function insertEntry(int $collectionId, string $title): void
    {
        $now = date('Y-m-d H:i:s');
        $this->db->insert(
            'INSERT INTO nb_entries (collection_id, title, slug, status, version, data, created_at, updated_at)
             VALUES (:c, :t, :s, :st, 1, :d, :ca, :u)',
            ['c' => $collectionId, 't' => $title, 's' => strtolower(str_replace(' ', '-', $title)), 'st' => 'published', 'd' => '{}', 'ca' => $now, 'u' => $now],
        );
    }

    public function test_a_narrow_role_cannot_browse_or_enumerate_collections(): void
    {
        $this->makeCollection('posts');
        $this->makeCollection('secret');
        $this->actingWithCapabilities(['media:read', 'media:write']); // no content read

        // The index enumerates nothing it can't read.
        $index = $this->get('/admin/collections');
        self::assertSame(200, $index->status);
        self::assertStringNotContainsString('/collections/posts/entries', $index->body);
        self::assertStringNotContainsString('/collections/secret/entries', $index->body);

        // A direct hit is turned away.
        $this->assertRedirects($this->get('/admin/collections/posts/entries'), Url::to('admin.collections.index'));
    }

    public function test_an_unreadable_collection_is_indistinguishable_from_a_missing_one(): void
    {
        $this->makeCollection('posts');
        $this->makeCollection('secret');
        $this->actingWithCapabilities(['posts:read']); // reads posts, not secret

        $unreadable = $this->get('/admin/collections/secret/entries');
        $missing    = $this->get('/admin/collections/does-not-exist/entries');

        // Same status AND same Location — no distinguisher.
        self::assertSame(302, $unreadable->status);
        self::assertSame($missing->status, $unreadable->status);
        self::assertSame($missing->header('Location'), $unreadable->header('Location'));
    }

    public function test_the_read_gate_covers_every_entry_route(): void
    {
        $c = $this->makeCollection('posts');
        $this->insertEntry($c->id, 'A post');
        $id = (int) $this->db->selectOne('SELECT id FROM nb_entries LIMIT 1')['id'];
        $this->actingWithCapabilities(['media:read']); // cannot read posts

        // Every GET route funnels through the read-gated mustFind → same redirect.
        foreach ([
            '/admin/collections/posts/entries',
            '/admin/collections/posts/entries/new',
            "/admin/collections/posts/entries/{$id}/edit",
        ] as $path) {
            $this->assertRedirects($this->get($path), Url::to('admin.collections.index'), $path);
        }
    }

    public function test_seeded_system_roles_still_browse_everything(): void
    {
        $this->makeCollection('posts');

        foreach (['admin', 'editor', 'author'] as $role) {
            $this->actingAs($role, $role . '@browse.test');
            $index = $this->get('/admin/collections');
            self::assertStringContainsString('/collections/posts/entries', $index->body, "{$role} sees the collection");
            self::assertSame(200, $this->get('/admin/collections/posts/entries')->status, "{$role} browses entries");
        }
    }

    public function test_a_read_only_user_on_a_singleton_is_not_trapped_in_a_loop(): void
    {
        $this->makeCollection('homepage', [], ['kind' => 'single', 'permissions' => ['manage' => ['editor']]]);
        $this->actingWithCapabilities(['homepage:read']); // read but not manage

        // The singleton index requires manage; denial must redirect ELSEWHERE
        // (not to the same URL), or the browser loops.
        $res = $this->get('/admin/collections/homepage/entries');
        self::assertSame(302, $res->status);
        self::assertSame(Url::to('admin.collections.index'), $res->header('Location'));
        self::assertNotSame('/admin/collections/homepage/entries', $res->header('Location'));
    }

    public function test_the_relation_picker_does_not_leak_an_unreadable_target(): void
    {
        $secret = $this->makeCollection('secret');
        $this->insertEntry($secret->id, 'TopSecretTitle');
        $this->makeCollection('posts', [
            ['handle' => 'related', 'label' => 'Related', 'type' => 'relation', 'required' => false, 'options' => ['target' => 'secret']],
        ]);

        // A posts manager who cannot read `secret`: the picker is empty.
        $this->actingWithCapabilities(['posts:write']);
        $form = $this->get('/admin/collections/posts/entries/new');
        self::assertSame(200, $form->status);
        self::assertStringNotContainsString('TopSecretTitle', $form->body, 'no unreadable target title in the picker');

        // Control: an admin (can read secret) DOES see it — proving the gate, not emptiness.
        $this->actingAs('admin');
        self::assertStringContainsString('TopSecretTitle', $this->get('/admin/collections/posts/entries/new')->body);
    }
}

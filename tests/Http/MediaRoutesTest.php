<?php

declare(strict_types=1);

namespace Nimbus\Tests\Http;

use Nimbus\Content\EntryInput;
use Nimbus\Content\EntryRepository;
use Nimbus\Content\EntryService;
use Nimbus\Content\FieldTypeRegistry;
use Nimbus\Content\RelationRepository;
use Nimbus\Media\MediaRepository;
use Nimbus\Support\EventDispatcher;

/**
 * The media admin routes through the real kernel.
 *
 * The upload happy path (a moved file) is covered by MediaUploaderTest, which
 * can inject a mover; here we exercise the HTTP wrapper — listing, deletion,
 * gating, CSRF, and the rejection responses that happen before any file move.
 */
final class MediaRoutesTest extends HttpTestCase
{
    private MediaRepository $media;

    protected function setUp(): void
    {
        parent::setUp();
        $this->media = new MediaRepository($this->db);
    }

    private function seed(string $filename = 'photo.png', string $mime = 'image/png'): int
    {
        return $this->media->create([
            'filename' => $filename,
            'path'     => '2026/08/' . bin2hex(random_bytes(8)) . '.png',
            'url'      => '/uploads/2026/08/x.png',
            'mime'     => $mime,
            'size'     => 1234,
            'width'    => 10,
            'height'   => 10,
            'alt'      => 'A dot',
        ], null);
    }

    // ------------------------------------------------------------- access

    public function test_anonymous_media_access_redirects_to_login(): void
    {
        $this->assertRedirects($this->get('/admin/media'), '/admin/login');
    }

    public function test_the_library_lists_uploaded_files(): void
    {
        $this->actingAs('admin');
        $this->seed('kitten.png');

        $response = $this->get('/admin/media');

        self::assertSame(200, $response->status);
        self::assertStringContainsString('kitten.png', $response->body);
        self::assertStringContainsString('Upload a file', $response->body);
    }

    public function test_empty_state_when_no_media(): void
    {
        $this->actingAs('admin');

        self::assertStringContainsString('No media yet', $this->get('/admin/media')->body);
    }

    // ----------------------------------------------------------- rejections

    public function test_uploading_with_no_file_reports_an_error(): void
    {
        $this->actingAs('admin');

        $response = $this->post('/admin/media'); // no file part

        // ADMIN-10: the error is server-rendered inline (200), not round-tripped
        // through a reflected ?err= redirect.
        self::assertSame(200, $response->status);
        self::assertStringContainsString('No file was selected.', $response->body);
    }

    public function test_uploading_requires_csrf(): void
    {
        $this->actingAs('admin');

        // requireCsrf aborts to /admin/media on a missing token.
        $this->assertRedirects($this->postWithoutCsrf('/admin/media'), '/admin/media');
    }

    public function test_a_write_without_read_actor_is_not_handed_the_library_on_error(): void
    {
        // A4: index() needs media:read but the write is only media:write — so a
        // write-without-read actor who hits an error gets a generic redirect,
        // never the re-rendered library listing.
        $this->actingWithCapabilities(['media:write']);
        $this->seed('secret-kitten.png');

        $response = $this->post('/admin/media'); // no file → error path
        $this->assertRedirects($response, '/admin/media?err=denied');
    }

    public function test_deleting_an_in_use_file_renders_the_usage_detail_escaped(): void
    {
        // A3: the in-use detail names the referencing entry (author-controlled
        // title) — it must be escaped and never round-tripped through the URL,
        // and an in-use file must not be deleted.
        $this->actingAs('admin');

        $collection = $this->makeCollection('gallery', [
            ['handle' => 'photo', 'label' => 'Photo', 'type' => 'media', 'required' => false, 'options' => []],
        ], ['kind' => 'collection', 'permissions' => ['manage' => ['admin']]]);
        $mediaId = $this->seed('used.png');

        $service = new EntryService($this->db, new EntryRepository($this->db), new RelationRepository($this->db), new FieldTypeRegistry(), new EventDispatcher());
        $service->save($collection, new EntryInput('"><script>alert(1)</script>', 'evil', 'published', ['photo' => $mediaId]), null, null);

        $response = $this->post("/admin/media/{$mediaId}/delete");

        self::assertSame(200, $response->status, 'an in-use delete re-renders, no redirect');
        self::assertStringContainsString('In use by', $response->body);
        self::assertStringNotContainsString('<script>alert(1)', $response->body, 'the entry title must be escaped');
        self::assertStringContainsString('&lt;script&gt;', $response->body);
        self::assertNotNull($this->media->find($mediaId), 'an in-use file is not deleted');
    }

    // ------------------------------------------------------------- deletion

    public function test_deleting_removes_the_row(): void
    {
        $this->actingAs('admin');
        $id = $this->seed();

        $response = $this->post("/admin/media/{$id}/delete");

        $this->assertRedirects($response, '/admin/media?msg=deleted');
        self::assertNull($this->media->find($id));
    }

    public function test_deleting_removes_the_file_on_disk(): void
    {
        $this->actingAs('admin');
        // A real file under the project's upload path, referenced by the row.
        $rel = 'public/uploads/2026/08/' . bin2hex(random_bytes(8)) . '.png';
        $abs = \Nimbus\Support\Config::basePath() . '/' . $rel;
        @mkdir(dirname($abs), 0o755, true);
        file_put_contents($abs, 'bytes');

        $id = $this->media->create([
            'filename' => 'f.png', 'path' => $rel, 'url' => '/uploads/x.png',
            'mime' => 'image/png', 'size' => 5, 'width' => 1, 'height' => 1, 'alt' => null,
        ], null);

        $this->post("/admin/media/{$id}/delete");

        self::assertFileDoesNotExist($abs, 'the stored file is removed with its row');
    }

    public function test_deleting_a_missing_item_is_a_no_op(): void
    {
        $this->actingAs('admin');

        $response = $this->post('/admin/media/99999/delete');

        $this->assertRedirects($response, '/admin/media?msg=deleted');
    }

    public function test_deletion_requires_csrf(): void
    {
        $this->actingAs('admin');
        $id = $this->seed();

        $this->postWithoutCsrf("/admin/media/{$id}/delete");

        self::assertNotNull($this->media->find($id), 'a forged delete must not remove media');
    }

    // ------------------------------------------------ capability gating (Slice 3b)

    public function test_a_media_less_role_is_denied_the_whole_library(): void
    {
        // A content-only role (no media caps) reaches the admin but not media.
        $this->actingWithCapabilities(['posts:read']);
        $id = $this->seed();

        $this->assertRedirects($this->get('/admin/media'), '/admin', 'listing needs media:read');
        $this->assertRedirects($this->post('/admin/media'), '/admin', 'uploading needs media:write');
        $this->assertRedirects($this->post("/admin/media/{$id}/delete"), '/admin', 'deleting needs media:write');
        self::assertNotNull($this->media->find($id), 'the file survives a denied delete');
    }

    public function test_a_read_only_media_role_can_list_but_never_write(): void
    {
        // The sharp one: media:read must NOT confer media:write (management caps
        // carry no read↔write implication), and each write is gated on its own.
        $this->actingWithCapabilities(['media:read']);
        $id = $this->seed();

        self::assertSame(200, $this->get('/admin/media')->status, 'media:read lists the library');

        // A denied write aborts to /admin (requireCan) — never reaching CSRF or
        // the no-file handler, which would redirect to /admin/media instead.
        $this->assertRedirects($this->post('/admin/media'), '/admin', 'upload is denied to a read-only role');
        $this->assertRedirects($this->post("/admin/media/{$id}/delete"), '/admin', 'delete is denied to a read-only role');
        self::assertNotNull($this->media->find($id), 'nothing was deleted');
    }

    public function test_editor_and_author_retain_media_access(): void
    {
        foreach (['editor', 'author'] as $role) {
            $this->actingAs($role, "{$role}@media.test");
            $id = $this->seed();

            self::assertSame(200, $this->get('/admin/media')->status, "{$role} lists media");
            // The write gate passes: an empty upload reaches the no-file handler,
            // which (for a read-capable actor) re-renders the library with the
            // error inline — not the authz abort to /admin.
            $noFile = $this->post('/admin/media');
            self::assertSame(200, $noFile->status, "{$role} reaches the no-file handler");
            self::assertStringContainsString('No file was selected.', $noFile->body);
            $this->assertRedirects($this->post("/admin/media/{$id}/delete"), '/admin/media?msg=deleted', "{$role} may delete");
            self::assertNull($this->media->find($id), "{$role}'s delete removed the row");
        }
    }

    public function test_media_caps_do_not_leak_into_other_management(): void
    {
        // Granting media:write is behavior-preserving, not an escalation: it
        // satisfies no other management gate.
        $this->actingWithCapabilities(['media:read', 'media:write']);

        $this->assertRedirects($this->get('/admin/users'), '/admin', 'media does not grant users');
        $this->assertRedirects($this->get('/admin/roles'), '/admin', 'nor roles');
        $this->assertRedirects($this->get('/admin/tokens'), '/admin', 'nor tokens');
    }

    public function test_the_media_nav_link_hides_without_media_read(): void
    {
        $this->actingWithCapabilities(['posts:read']);

        self::assertStringNotContainsString('/admin/media', $this->get('/admin')->body, 'no dead media link for a media-less user');
    }

    // --------------------------------------------------------------- escaping

    public function test_filenames_are_html_escaped(): void
    {
        $this->actingAs('admin');
        $this->seed('<script>alert(1)</script>.png');

        $body = $this->get('/admin/media')->body;

        self::assertStringNotContainsString('<script>alert(1)</script>', $body);
    }
}

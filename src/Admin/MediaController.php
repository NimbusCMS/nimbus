<?php

declare(strict_types=1);

namespace Nimbus\Admin;

use Nimbus\Auth\Auth;
use Nimbus\Database\Connection;
use Nimbus\Http\Csrf;
use Nimbus\Http\Request;
use Nimbus\Http\Response;
use Nimbus\Http\Router;
use Nimbus\Http\Url;
use Nimbus\Media\MediaInUse;
use Nimbus\Media\MediaRepository;
use Nimbus\Media\MediaService;
use Nimbus\Media\MediaUploader;
use Nimbus\Media\MediaUsageRepository;
use Nimbus\Media\UploadError;
use Nimbus\Support\Config;

/**
 * The media library: upload, list and delete files.
 *
 * Uploading is a write, so it lives here in the admin behind a session and
 * CSRF — the read-only API never accepts files. The security of what actually
 * lands on disk is MediaUploader's job; this controller is the HTTP wrapper
 * around it.
 */
final class MediaController extends Controller
{
    private MediaRepository $media;
    private MediaUploader $uploader;
    private MediaService $service;

    public function __construct(Connection $db, Auth $auth, ?AdminPageRegistry $adminPages = null)
    {
        parent::__construct($db, $auth, $adminPages);
        $this->media    = new MediaRepository($this->db);
        $this->uploader = new MediaUploader(
            $this->media,
            Config::uploadPath(),
            Config::uploadUrl(),
            Config::uploadMaxBytes(),
        );
        $this->service = new MediaService($this->media, new MediaUsageRepository($this->db), Config::basePath());
    }

    public function routes(Router $r): void
    {
        $r->group('/admin/media', [$this->authMw], function (Router $g): void {
            $g->get('', fn (Request $req, array $p): Response => $this->index($req))->name('admin.media.index');
            $g->post('', fn (Request $req, array $p): Response => $this->store($req));
            $g->post('/{id}/delete', fn (Request $req, array $p): Response => $this->destroy($req, (int) $p['id']));
        });
    }

    private function index(Request $req): Response
    {
        $this->requireCan('media', 'read');

        return $this->page('media/index', 'media', [
            'items'    => $this->media->all(),
            'maxLabel' => $this->humanBytes(Config::uploadMaxBytes()),
            'flash'    => $req->query('msg'),
            'error'    => $req->query('err'),
            'csrf'     => Csrf::token(),
        ]);
    }

    private function store(Request $req): Response
    {
        // Uploading is a media write; gated independently of the read on index so
        // a read-only media role cannot add files (management caps grant no
        // read-implies-write, and write no read — both are checked where used).
        $this->requireCan('media', 'write');
        $this->requireCsrf($req, Url::to('admin.media.index'));

        $file = $req->file('file');
        if ($file === null) {
            return $this->redirect(Url::to('admin.media.index') . '?err=' . rawurlencode('No file was selected.'));
        }
        if ($this->tooLong($req->input('alt'), 255)) { // nb_media.alt VARCHAR(255)
            return $this->redirect(Url::to('admin.media.index') . '?err=' . rawurlencode('Alt text must be 255 characters or fewer.'));
        }

        try {
            $this->uploader->store($file, $this->auth->user()?->id, $req->input('alt'));
        } catch (UploadError $e) {
            // The message is user-safe by construction.
            return $this->redirect(Url::to('admin.media.index') . '?err=' . rawurlencode($e->getMessage()));
        }
        return $this->redirect(Url::to('admin.media.index') . '?msg=uploaded');
    }

    private function destroy(Request $req, int $id): Response
    {
        $this->requireCan('media', 'write');
        $this->requireCsrf($req, Url::to('admin.media.index'));

        // The shared guard refuses to delete a file that content still uses, so
        // an image never vanishes from a live page. It reports where, so the
        // editor knows what to detach first.
        try {
            $this->service->delete($id);
        } catch (MediaInUse $e) {
            $where = array_map(static fn (array $u): string => "{$u['entry_title']} ({$u['collection']}/{$u['field_handle']})", $e->usage);
            return $this->redirect(Url::to('admin.media.index') . '?err=' . rawurlencode('In use by: ' . implode(', ', $where)));
        }
        return $this->redirect(Url::to('admin.media.index') . '?msg=deleted');
    }

    private function humanBytes(int $bytes): string
    {
        return $bytes >= 1_048_576 ? round($bytes / 1_048_576, 1) . ' MB' : round($bytes / 1024) . ' KB';
    }
}

<?php

declare(strict_types=1);

namespace Nimbus\Admin;

use Nimbus\Auth\Auth;
use Nimbus\Database\Connection;
use Nimbus\Http\Csrf;
use Nimbus\Http\Request;
use Nimbus\Http\Response;
use Nimbus\Http\Router;
use Nimbus\Media\MediaRepository;
use Nimbus\Media\MediaUploader;
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

    public function __construct(Connection $db, Auth $auth)
    {
        parent::__construct($db, $auth);
        $this->media    = new MediaRepository($this->db);
        $this->uploader = new MediaUploader(
            $this->media,
            Config::uploadPath(),
            Config::uploadUrl(),
            Config::uploadMaxBytes(),
        );
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
        $this->requireCsrf($req, '/admin/media');

        $file = $req->file('file');
        if ($file === null) {
            return $this->redirect('/admin/media?err=' . rawurlencode('No file was selected.'));
        }

        try {
            $this->uploader->store($file, $this->auth->user()?->id, $req->input('alt'));
        } catch (UploadError $e) {
            // The message is user-safe by construction.
            return $this->redirect('/admin/media?err=' . rawurlencode($e->getMessage()));
        }
        return $this->redirect('/admin/media?msg=uploaded');
    }

    private function destroy(Request $req, int $id): Response
    {
        $this->requireCsrf($req, '/admin/media');

        $item = $this->media->find($id);
        if ($item !== null) {
            // Remove the row first, then the file. A leftover file is harmless;
            // a row pointing at a deleted file is not, so metadata goes first.
            $this->media->delete($id);
            // path is stored relative to the project root (e.g. public/uploads/2026/08/x.jpg).
            $abs = Config::basePath() . '/' . ltrim($item->path, '/');
            if (is_file($abs)) {
                @unlink($abs);
            }
        }
        return $this->redirect('/admin/media?msg=deleted');
    }

    private function humanBytes(int $bytes): string
    {
        return $bytes >= 1_048_576 ? round($bytes / 1_048_576, 1) . ' MB' : round($bytes / 1024) . ' KB';
    }
}

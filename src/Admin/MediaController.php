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
use Nimbus\Settings\Settings;
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
    /** ADMIN-10: only success codes survive a redirect; errors are server-rendered. */
    private const OK_NOTICES = [
        'uploaded' => 'File uploaded.',
        'deleted'  => 'File deleted.',
    ];
    /** The only error code — the A4 fallback when a media:write actor without
     *  media:read triggers an error we can't render the library page for. */
    private const ERR_NOTICES = [
        'denied' => 'That action couldn’t be completed.',
    ];

    private MediaRepository $media;
    private MediaUploader $uploader;
    private MediaService $service;

    public function __construct(Connection $db, Auth $auth, Settings $settings, ?AdminPageRegistry $adminPages = null)
    {
        parent::__construct($db, $auth, $settings, $adminPages);
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

    /** $error is a server-built failure message to show inline (never from the
     *  URL — ADMIN-10); null on a normal GET. */
    private function index(Request $req, ?string $error = null): Response
    {
        $this->requireCan('media', 'read');

        return $this->page('media/index', 'media', [
            'items'    => $this->media->all(),
            'maxLabel' => $this->humanBytes(Config::uploadMaxBytes()),
            'notice'   => $this->notice($req, self::OK_NOTICES, self::ERR_NOTICES),
            'error'    => $error,
            'csrf'     => Csrf::token(),
        ]);
    }

    /**
     * A media write action failed. The library listing is media:read while the
     * write itself is only media:write (deliberately independent) — so re-render
     * the library with the error ONLY when this actor may read it (A4); otherwise
     * redirect with a generic code, never handing a write-without-read actor the
     * listing. The message is server-built and escaped by the view (never
     * round-tripped through the URL).
     */
    private function renderMediaError(Request $req, string $message): Response
    {
        if ($this->gate->can('media', 'read')) {
            return $this->index($req, $message);
        }
        return $this->redirect(Url::to('admin.media.index') . '?err=denied');
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
            return $this->renderMediaError($req, 'No file was selected.');
        }
        if ($this->tooLong($req->input('alt'), 255)) { // nb_media.alt VARCHAR(255)
            return $this->renderMediaError($req, 'Alt text must be 255 characters or fewer.');
        }

        try {
            $this->uploader->store($file, $this->auth->user()?->id, $req->input('alt'));
        } catch (UploadError $e) {
            // The message is user-safe by construction (server-built, no request text).
            return $this->renderMediaError($req, $e->getMessage());
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
            // The usage detail includes entry titles (author-controlled) — it is
            // server-rendered and escaped by the view (A3), never round-tripped
            // through the URL, and only shown to an actor who may read the library.
            $where = array_map(static fn (array $u): string => "{$u['entry_title']} ({$u['collection']}/{$u['field_handle']})", $e->usage);
            return $this->renderMediaError($req, 'In use by: ' . implode(', ', $where));
        }
        return $this->redirect(Url::to('admin.media.index') . '?msg=deleted');
    }

    private function humanBytes(int $bytes): string
    {
        return $bytes >= 1_048_576 ? round($bytes / 1_048_576, 1) . ' MB' : round($bytes / 1024) . ' KB';
    }
}

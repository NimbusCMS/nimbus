<?php

declare(strict_types=1);

namespace Nimbus\Admin;

use Nimbus\Auth\Auth;
use Nimbus\Content\Collection;
use Nimbus\Content\CollectionRepository;
use Nimbus\Content\EntryInput;
use Nimbus\Content\EntryRepository;
use Nimbus\Content\EntryService;
use Nimbus\Content\FieldTypeRegistry;
use Nimbus\Content\Publication;
use Nimbus\Content\RelationRepository;
use Nimbus\Database\Connection;
use Nimbus\Http\Csrf;
use Nimbus\Http\Request;
use Nimbus\Http\Response;
use Nimbus\Http\Router;
use Nimbus\Http\Url;
use Nimbus\Media\MediaRepository;
use Nimbus\Settings\Settings;
use Nimbus\Support\EventDispatcher;

/**
 * Managing content *inside* a collection: listing, creating, editing and
 * deleting entries.
 *
 * The counterpart to CollectionsController, which administers the schema. The
 * two are separated because they answer to different people and different
 * rules: defining a content type is an administrator action that changes the
 * shape of the data, while writing an entry is an everyday editorial action
 * gated by the collection's own manage permissions.
 *
 * This controller owns only HTTP concerns — mapping a request to an EntryInput,
 * preparing relation pickers, and re-rendering the form with errors. Every
 * write goes through EntryService, which owns validation, slugs, singletons,
 * the transaction and the events.
 */
final class EntriesController extends Controller
{
    /** Entries per admin list page. Admins scan/manage in bulk, so a touch larger than the site's reader page. */
    private const PER_PAGE = 25;

    private CollectionRepository $collections;
    private EntryRepository $entries;
    private RelationRepository $relations;
    private EntryService $entryService;

    /**
     * $fieldTypes and $events are the application's single instances. A plugin
     * registering a field type registers it into *this* registry, so building a
     * local one here would make plugin types invisible to entry forms.
     */
    public function __construct(
        Connection $db,
        Auth $auth,
        Settings $settings,
        private FieldTypeRegistry $types,
        EventDispatcher $events,
        ?AdminPageRegistry $adminPages = null,
    ) {
        parent::__construct($db, $auth, $settings, $adminPages);
        $this->collections  = new CollectionRepository($this->db);
        $this->entries      = new EntryRepository($this->db);
        $this->relations    = new RelationRepository($this->db);
        $this->entryService = new EntryService($this->db, $this->entries, $this->relations, $this->types, $events);
    }

    public function routes(Router $r): void
    {
        $r->group('/admin/collections/{handle}/entries', [$this->authMw], function (Router $g): void {
            $g->get('', fn (Request $req, array $p): Response => $this->index($req, $p['handle']))->name('admin.entries.index');
            $g->get('/new', fn (Request $req, array $p): Response => $this->form($p['handle'], null))->name('admin.entries.new');
            $g->post('', fn (Request $req, array $p): Response => $this->store($req, $p['handle']));
            $g->get('/{id}/edit', fn (Request $req, array $p): Response => $this->form($p['handle'], (int) $p['id']))->name('admin.entries.edit');
            $g->post('/{id}', fn (Request $req, array $p): Response => $this->update($req, $p['handle'], (int) $p['id']));
            $g->post('/{id}/publish', fn (Request $req, array $p): Response => $this->setStatus($req, $p['handle'], (int) $p['id'], Publication::PUBLISHED));
            $g->post('/{id}/unpublish', fn (Request $req, array $p): Response => $this->setStatus($req, $p['handle'], (int) $p['id'], Publication::DRAFT));
            $g->post('/{id}/delete', fn (Request $req, array $p): Response => $this->destroy($req, $p['handle'], (int) $p['id']));
        });
    }

    // ================================================================ actions

    private function index(Request $req, string $handle): Response
    {
        $collection = $this->mustFind($handle);

        // A singleton has no list — go straight to editing its one entry.
        if ($collection->isSingle()) {
            $this->requireManage($collection);
            $entry = $this->entries->firstForCollection($collection->id);
            return $this->renderForm($collection, $this->modelFromEntry($collection, $entry), [], $req->query('msg'));
        }

        // Paginate: count first (search-aware), derive total pages, then clamp
        // the requested page into range so a too-high ?page can't produce a huge
        // OFFSET or a dead "Next".
        $q          = $req->query('q');
        $total      = $this->entries->countForCollection($collection->id, $q);
        $totalPages = $total === 0 ? 1 : (int) ceil($total / self::PER_PAGE);
        $page       = min(max(1, (int) $req->query('page')), $totalPages);
        $rows       = $this->entries->forCollection($collection->id, $q, self::PER_PAGE, ($page - 1) * self::PER_PAGE);

        return $this->page('entries/index', 'collections', [
            'collection'  => $collection,
            'rows'        => $rows,
            'types'       => $this->types,
            'canManage'   => $this->gate->manages($collection),
            'canSchema'   => $this->gate->can('schema', 'write'),
            'flash'       => $req->query('msg'),
            'page'        => $page,
            'total_pages' => $totalPages,
            'total'       => $total,
            'q'           => $q,
        ]);
    }

    private function form(string $handle, ?int $id): Response
    {
        $collection = $this->mustFind($handle);
        $this->requireManage($collection);

        // find() is scoped by collection, so an id belonging to another
        // collection reads as missing rather than being editable from here.
        $entry = $id !== null ? $this->entries->find($collection->id, $id) : null;
        if ($id !== null && $entry === null) {
            return $this->redirect(Url::to('admin.entries.index', ['handle' => $handle]));
        }
        return $this->renderForm($collection, $this->modelFromEntry($collection, $entry), []);
    }

    private function store(Request $req, string $handle): Response
    {
        $collection = $this->mustFind($handle);
        $this->requireManage($collection);
        $this->requireCsrf($req);

        return $this->save($collection, $req, null);
    }

    private function update(Request $req, string $handle, int $id): Response
    {
        $collection = $this->mustFind($handle);
        $this->requireManage($collection);
        $this->requireCsrf($req);

        if ($this->entries->find($collection->id, $id) === null) {
            return $this->redirect(Url::to('admin.entries.index', ['handle' => $handle]));
        }
        return $this->save($collection, $req, $id);
    }

    private function destroy(Request $req, string $handle, int $id): Response
    {
        $collection = $this->mustFind($handle);
        $this->requireManage($collection);
        $this->requireCsrf($req);

        // Singletons aren't deleted as entries — there's always exactly one.
        if ($collection->isSingle() || $this->entries->find($collection->id, $id) === null) {
            return $this->redirect(Url::to('admin.entries.index', ['handle' => $handle]));
        }
        // The redirect is the same either way — the user asked for it gone and
        // it is gone. Only the event needs to know whether a row really went.
        $this->entryService->delete($collection, $id);
        return $this->redirect(Url::to('admin.entries.index', ['handle' => $handle]) . '?msg=deleted');
    }

    /**
     * Publish / unpublish quick action: flip only the status, keeping the
     * entry's fields exactly as stored. Goes through EntryService like any
     * other save, so validation, events and the published_at rules all apply —
     * publishing sets the time to now (or keeps an existing one), unpublishing
     * to draft keeps it for a later re-publish.
     */
    private function setStatus(Request $req, string $handle, int $id, string $status): Response
    {
        $collection = $this->mustFind($handle);
        $this->requireManage($collection);
        $this->requireCsrf($req);

        $entry = $this->entries->find($collection->id, $id);
        if ($entry === null || $collection->isSingle()) {
            return $this->redirect(Url::to('admin.entries.index', ['handle' => $handle]));
        }

        // Rebuild the input from what is stored, changing only the status.
        $values = is_array($entry['data']) ? $entry['data'] : [];
        foreach ($collection->fields as $field) {
            if ($field->type === 'relation') {
                $values[$field->handle] = $this->relations->targets($id, $field->id);
            }
        }
        $input  = new EntryInput((string) $entry['title'], (string) $entry['slug'], $status, $values);
        $result = $this->entryService->save($collection, $input, $id, $this->auth->user()?->id);

        $msg = $result->successful ? ($status === Publication::PUBLISHED ? 'published' : 'unpublished') : 'error';
        return $this->redirect(Url::to('admin.entries.index', ['handle' => $handle]) . '?msg=' . $msg);
    }

    /** Request -> EntryInput -> EntryService; re-render with errors, or redirect. */
    private function save(Collection $collection, Request $req, ?int $id): Response
    {
        $input  = $this->inputFromRequest($collection, $req);
        $result = $this->entryService->save($collection, $input, $id, $this->auth->user()?->id);

        if (!$result->successful) {
            // Render what was submitted, never a re-read of storage. The admin
            // shows prose (the projection); the top-level message covers the
            // non-field case (a missing field-type provider).
            return $this->renderForm($collection, $this->modelFromInput($input, $id), $result->messages(), null, $result->message);
        }
        $msg = $id === null ? 'created' : ($collection->isSingle() ? 'saved' : 'updated');
        return $this->redirect(Url::to('admin.entries.index', ['handle' => $collection->handle]) . '?msg=' . $msg);
    }

    // ================================================================ helpers

    /**
     * @param array<string,mixed>  $model
     * @param array<string,string> $errors   per-field human messages, keyed by input name
     * @param string               $topError a non-field failure (e.g. missing provider), or ''
     */
    private function renderForm(Collection $collection, array $model, array $errors, ?string $flash = null, string $topError = ''): Response
    {
        // Relation pickers need their target collection's entries (id => title).
        $relationOptions = [];
        $hasMedia        = false;
        foreach ($collection->fields as $field) {
            if ($field->type === 'relation') {
                $target = (string) $field->option('target', '') !== '' ? $this->collections->findByHandle((string) $field->option('target')) : null;
                // Read-gate the target: the picker must not leak the titles of a
                // collection the user can't read (the API gates relation expansion
                // the same way). Value integrity of the relation is Slice C.
                $relationOptions[$field->handle] = ($target !== null && $this->gate->reads($target)) ? $this->entries->titleMap($target->id) : [];
            }
            $hasMedia = $hasMedia || $field->type === 'media';
        }

        // Media pickers offer the library (id => filename); only queried when needed.
        $mediaOptions = [];
        if ($hasMedia) {
            foreach ((new MediaRepository($this->db))->all() as $item) {
                $mediaOptions[$item->id] = $item->filename;
            }
        }

        return $this->page('entries/form', 'collections', [
            'collection'      => $collection,
            'model'           => $model,
            'errors'          => $errors,
            'topError'        => $topError,
            'flash'           => $flash,
            'types'           => $this->types,
            'relationOptions' => $relationOptions,
            'mediaOptions'    => $mediaOptions,
            'csrf'            => Csrf::token(),
        ]);
    }

    /**
     * Editing/new: build the form model from a stored entry (or field defaults).
     *
     * @param array<string,mixed>|null $entry
     * @return array<string,mixed>
     */
    private function modelFromEntry(Collection $collection, ?array $entry): array
    {
        if ($entry !== null) {
            $values = is_array($entry['data']) ? $entry['data'] : [];
            foreach ($collection->fields as $field) {
                if ($field->type === 'relation') {
                    $values[$field->handle] = $this->relations->targets((int) $entry['id'], $field->id);
                }
            }
            $status      = (string) $entry['status'];
            $publishedAt = $entry['published_at'] ?? null;
            return [
                'id'                 => (int) $entry['id'],
                'title'              => (string) $entry['title'],
                'slug'               => (string) $entry['slug'],
                'status'             => $status,
                'published_at_input' => $this->toDatetimeLocal($publishedAt),
                'state'              => Publication::state($status, $publishedAt),
                'values'             => $values,
            ];
        }
        $values = [];
        foreach ($collection->fields as $field) {
            $values[$field->handle] = $field->type === 'relation' ? [] : $field->option('default', '');
        }
        return ['id' => null, 'title' => '', 'slug' => '', 'status' => 'draft', 'published_at_input' => '', 'state' => 'draft', 'values' => $values];
    }

    /** A stored "Y-m-d H:i:s" as the "Y-m-d\TH:i" a datetime-local input expects. */
    private function toDatetimeLocal(?string $stored): string
    {
        if ($stored === null || $stored === '') {
            return '';
        }
        // An unparseable value re-renders blank (with its field error) rather than
        // as 1970-01-01 (strtotime false → 0) — the datetime-local input can't hold
        // the raw invalid string anyway.
        $ts = strtotime($stored);
        return $ts === false ? '' : date('Y-m-d\TH:i', $ts);
    }

    /** Build the typed input object from the request (with normalized values). */
    private function inputFromRequest(Collection $collection, Request $req): EntryInput
    {
        $posted = $req->all()['f'] ?? [];
        $values = [];
        foreach ($collection->fields as $field) {
            $raw = is_array($posted) ? ($posted[$field->handle] ?? null) : null;
            // forDisplay(), not get(): an unavailable type must not fatal here.
            // MissingType hands the value back untouched, and EntryService then
            // rejects the save with a message naming the missing provider.
            $values[$field->handle] = $this->types->forDisplay($field->type)->normalize($raw);
        }
        $status = (string) $req->input('status');
        $status = Publication::isStoredStatus($status) ? $status : Publication::DRAFT;

        return new EntryInput(
            trim((string) $req->input('title')),
            trim((string) $req->input('slug')),
            $status,
            $values,
            $this->publishedAtInput($req),
        );
    }

    /** The submitted publish time, or null. Empty/whitespace means "let the service decide". */
    private function publishedAtInput(Request $req): ?string
    {
        $raw = trim((string) $req->input('published_at'));
        return $raw !== '' ? $raw : null;
    }

    /**
     * Re-render the form after a failed save, preserving what the user typed.
     *
     * @return array<string,mixed>
     */
    private function modelFromInput(EntryInput $input, ?int $id): array
    {
        return [
            'id'                 => $id,
            'title'              => $input->title,
            'slug'               => $input->slug,
            'status'             => $input->status,
            'published_at_input' => $input->publishedAt !== null ? $this->toDatetimeLocal($input->publishedAt) : '',
            'state'              => Publication::state($input->status, $input->publishedAt),
            'values'             => $input->values,
        ];
    }

    /**
     * Resolve a collection for browsing/editing, read-gated (ADR 0011). A
     * collection the user cannot read is treated **exactly like one that does not
     * exist** — the same redirect — so an out-of-scope collection cannot be told
     * apart from a missing one (non-enumeration). This is the one choke point for
     * every entry route (list, form, store, update, publish, delete); writes are
     * additionally gated by {@see requireManage()}, and a content write implies
     * read so every manager passes here.
     */
    private function mustFind(string $handle): Collection
    {
        $collection = $this->collections->findByHandle($handle);
        if ($collection === null || !$this->gate->reads($collection)) {
            $this->abortTo(Url::to('admin.collections.index'));
        }
        return $collection;
    }

    private function requireManage(Collection $collection): void
    {
        if (!$this->gate->manages($collection)) {
            // Abort to the collections index, never to this collection's own
            // entries URL — a singleton's index IS this route, so redirecting
            // there would loop.
            $this->abortTo(Url::to('admin.collections.index'));
        }
    }
}

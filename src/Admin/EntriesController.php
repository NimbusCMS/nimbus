<?php

declare(strict_types=1);

namespace Nimbus\Admin;

use Nimbus\Content\Collection;
use Nimbus\Content\CollectionRepository;
use Nimbus\Content\EntryInput;
use Nimbus\Content\EntryRepository;
use Nimbus\Content\EntryService;
use Nimbus\Content\FieldTypeRegistry;
use Nimbus\Content\Permissions;
use Nimbus\Content\RelationRepository;
use Nimbus\Http\Csrf;
use Nimbus\Http\Request;
use Nimbus\Http\Response;
use Nimbus\Http\Router;

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
    private CollectionRepository $collections;
    private EntryRepository $entries;
    private RelationRepository $relations;
    private FieldTypeRegistry $types;
    private EntryService $entryService;

    public function boot(): void
    {
        $this->collections  = new CollectionRepository($this->db);
        $this->entries      = new EntryRepository($this->db);
        $this->relations    = new RelationRepository($this->db);
        $this->types        = new FieldTypeRegistry();
        $this->entryService = new EntryService($this->db, $this->entries, $this->relations, $this->types);
    }

    public function routes(Router $r): void
    {
        $this->boot();

        $r->group('/admin/collections/{handle}/entries', [$this->authMw], function (Router $g): void {
            $g->get('', fn (Request $req, array $p): Response => $this->index($req, $p['handle']))->name('admin.entries.index');
            $g->get('/new', fn (Request $req, array $p): Response => $this->form($p['handle'], null))->name('admin.entries.new');
            $g->post('', fn (Request $req, array $p): Response => $this->store($req, $p['handle']));
            $g->get('/{id}/edit', fn (Request $req, array $p): Response => $this->form($p['handle'], (int) $p['id']))->name('admin.entries.edit');
            $g->post('/{id}', fn (Request $req, array $p): Response => $this->update($req, $p['handle'], (int) $p['id']));
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

        return $this->page('entries/index', 'collections', [
            'collection' => $collection,
            'rows'       => $this->entries->forCollection($collection->id, $req->query('q')),
            'types'      => $this->types,
            'canManage'  => Permissions::canManage($this->auth->user(), $collection),
            'flash'      => $req->query('msg'),
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
            return $this->redirect("/admin/collections/{$handle}/entries");
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
            return $this->redirect("/admin/collections/{$handle}/entries");
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
            return $this->redirect("/admin/collections/{$handle}/entries");
        }
        // The redirect is the same either way — the user asked for it gone and
        // it is gone. Only the event needs to know whether a row really went.
        $this->entryService->delete($collection, $id);
        return $this->redirect("/admin/collections/{$handle}/entries?msg=deleted");
    }

    /** Request -> EntryInput -> EntryService; re-render with errors, or redirect. */
    private function save(Collection $collection, Request $req, ?int $id): Response
    {
        $input  = $this->inputFromRequest($collection, $req);
        $result = $this->entryService->save($collection, $input, $id, $this->auth->user()?->id);

        if (!$result->successful) {
            // Render what was submitted, never a re-read of storage.
            return $this->renderForm($collection, $this->modelFromInput($input, $id), $result->errors);
        }
        $msg = $id === null ? 'created' : ($collection->isSingle() ? 'saved' : 'updated');
        return $this->redirect("/admin/collections/{$collection->handle}/entries?msg={$msg}");
    }

    // ================================================================ helpers

    /**
     * @param array<string,mixed>  $model
     * @param array<string,string> $errors
     */
    private function renderForm(Collection $collection, array $model, array $errors, ?string $flash = null): Response
    {
        // Relation pickers need their target collection's entries (id => title).
        $relationOptions = [];
        foreach ($collection->fields as $field) {
            if ($field->type === 'relation') {
                $target = (string) $field->option('target', '') !== '' ? $this->collections->findByHandle((string) $field->option('target')) : null;
                $relationOptions[$field->handle] = $target !== null ? $this->entries->titleMap($target->id) : [];
            }
        }
        return $this->page('entries/form', 'collections', [
            'collection'      => $collection,
            'model'           => $model,
            'errors'          => $errors,
            'flash'           => $flash,
            'types'           => $this->types,
            'relationOptions' => $relationOptions,
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
            return [
                'id'     => (int) $entry['id'],
                'title'  => (string) $entry['title'],
                'slug'   => (string) $entry['slug'],
                'status' => (string) $entry['status'],
                'values' => $values,
            ];
        }
        $values = [];
        foreach ($collection->fields as $field) {
            $values[$field->handle] = $field->type === 'relation' ? [] : $field->option('default', '');
        }
        return ['id' => null, 'title' => '', 'slug' => '', 'status' => 'draft', 'values' => $values];
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
        return new EntryInput(
            trim((string) $req->input('title')),
            trim((string) $req->input('slug')),
            in_array($req->input('status'), ['draft', 'published'], true) ? (string) $req->input('status') : 'draft',
            $values,
        );
    }

    /**
     * Re-render the form after a failed save, preserving what the user typed.
     *
     * @return array<string,mixed>
     */
    private function modelFromInput(EntryInput $input, ?int $id): array
    {
        return ['id' => $id, 'title' => $input->title, 'slug' => $input->slug, 'status' => $input->status, 'values' => $input->values];
    }

    private function mustFind(string $handle): Collection
    {
        $collection = $this->collections->findByHandle($handle);
        if ($collection === null) {
            $this->abortTo('/admin/collections');
        }
        return $collection;
    }

    private function requireManage(Collection $collection): void
    {
        if (!Permissions::canManage($this->auth->user(), $collection)) {
            $this->abortTo("/admin/collections/{$collection->handle}/entries");
        }
    }
}

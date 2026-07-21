<?php

declare(strict_types=1);

namespace Nimbus\Admin;

use Nimbus\Content\Collection;
use Nimbus\Content\CollectionRepository;
use Nimbus\Content\CollectionService;
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
use Nimbus\Support\Str;

/**
 * The Collections engine: define content types + fields (admins), then manage
 * entries against them (anyone the collection's permissions allow). Entry forms
 * are generated from field definitions via the FieldTypeRegistry.
 */
final class CollectionsController extends Controller
{
    private CollectionRepository $collections;
    private EntryRepository $entries;
    private RelationRepository $relations;
    private FieldTypeRegistry $types;
    private EntryService $entryService;
    private CollectionService $collectionService;

    public function boot(): void
    {
        $this->collections       = new CollectionRepository($this->db);
        $this->entries           = new EntryRepository($this->db);
        $this->relations         = new RelationRepository($this->db);
        $this->types             = new FieldTypeRegistry();
        $this->entryService      = new EntryService($this->db, $this->entries, $this->relations, $this->types);
        $this->collectionService = new CollectionService($this->db, $this->collections);
    }

    public function routes(Router $r): void
    {
        $this->boot();

        $r->group('/admin/collections', [$this->authMw], function (Router $g): void {
            // ---- collections (structural: admin only) ----
            $g->get('', fn (): Response => $this->index())->name('admin.collections.index');
            $g->get('/new', fn (): Response => $this->form(null))->name('admin.collections.new');
            $g->post('', fn (): Response => $this->store());
            $g->get('/{id}/edit', fn (array $p): Response => $this->form((int) $p['id']))->name('admin.collections.edit');
            $g->post('/{id}', fn (array $p): Response => $this->update((int) $p['id']));
            $g->post('/{id}/delete', fn (array $p): Response => $this->destroy((int) $p['id']));

            // ---- entries ----
            $g->get('/{handle}/entries', fn (array $p): Response => $this->entriesIndex($p['handle']))->name('admin.entries.index');
            $g->get('/{handle}/entries/new', fn (array $p): Response => $this->entryForm($p['handle'], null))->name('admin.entries.new');
            $g->post('/{handle}/entries', fn (array $p): Response => $this->entryStore($p['handle']));
            $g->get('/{handle}/entries/{id}/edit', fn (array $p): Response => $this->entryForm($p['handle'], (int) $p['id']))->name('admin.entries.edit');
            $g->post('/{handle}/entries/{id}', fn (array $p): Response => $this->entryUpdate($p['handle'], (int) $p['id']));
            $g->post('/{handle}/entries/{id}/delete', fn (array $p): Response => $this->entryDestroy($p['handle'], (int) $p['id']));
        });
    }

    // =========================================================== collections

    private function index(): Response
    {
        $rows = [];
        foreach ($this->collections->all() as $c) {
            $rows[] = [
                'c'       => $c,
                'fields'  => $this->collections->fieldCount($c->id),
                'entries' => $this->collections->entryCount($c->id),
            ];
        }
        return $this->page('collections/index', 'collections', [
            'rows'    => $rows,
            'isAdmin' => Permissions::isAdmin($this->auth->user()),
            'flash'   => Request::fromGlobals()->query('msg'),
        ]);
    }

    private function form(?int $id): Response
    {
        $this->requireAdmin();
        $collection = $id !== null ? $this->collections->find($id) : null;
        if ($id !== null && $collection === null) {
            return $this->redirect('/admin/collections');
        }
        $collectionOptions = [];
        foreach ($this->collections->all() as $c) {
            $collectionOptions[$c->handle] = $c->name;
        }
        return $this->page('collections/form', 'collections', [
            'collection'        => $collection,
            'typeChoices'       => $this->types->choices(),
            'choiceTypes'       => $this->choiceTypes(),
            'relationTypes'     => ['relation'],
            'collectionOptions' => $collectionOptions,
            'roles'             => Permissions::ROLES,
            'csrf'              => Csrf::token(),
        ]);
    }

    private function store(): Response
    {
        $this->requireAdmin();
        $req = Request::fromGlobals();
        $this->requireCsrf($req);

        $name   = trim((string) $req->input('name'));
        $handle = Str::handle($req->input('handle') ?: $name);
        if ($name === '' || $handle === '' || $this->collections->handleExists($handle)) {
            return $this->redirect('/admin/collections/new');
        }

        try {
            $this->collectionService->create($handle, $name, $this->icon($req), (string) $req->input('description'), $this->options($req), $this->fieldDefs($req));
        } catch (\PDOException $e) {
            if (\Nimbus\Database\Connection::isDuplicateKey($e)) {
                return $this->redirect('/admin/collections/new'); // handle taken (race)
            }
            throw $e;
        }
        return $this->redirect('/admin/collections?msg=created');
    }

    private function update(int $id): Response
    {
        $this->requireAdmin();
        $req = Request::fromGlobals();
        $this->requireCsrf($req);

        if ($this->collections->find($id) === null) {
            return $this->redirect('/admin/collections');
        }
        $name = trim((string) $req->input('name'));
        if ($name === '') {
            return $this->redirect("/admin/collections/{$id}/edit");
        }
        $this->collectionService->update($id, $name, $this->icon($req), (string) $req->input('description'), $this->options($req), $this->fieldDefs($req));
        return $this->redirect('/admin/collections?msg=updated');
    }

    private function destroy(int $id): Response
    {
        $this->requireAdmin();
        $this->requireCsrf(Request::fromGlobals());
        $this->collectionService->delete($id);
        return $this->redirect('/admin/collections?msg=deleted');
    }

    // =============================================================== entries

    private function entriesIndex(string $handle): Response
    {
        $collection = $this->mustFind($handle);

        // A singleton has no list — go straight to editing its one entry.
        if ($collection->isSingle()) {
            $this->requireManage($collection);
            $entry = $this->entries->firstForCollection($collection->id);
            return $this->renderEntryForm($collection, $this->modelFromEntry($collection, $entry), [], Request::fromGlobals()->query('msg'));
        }

        return $this->page('entries/index', 'collections', [
            'collection' => $collection,
            'rows'       => $this->entries->forCollection($collection->id, Request::fromGlobals()->query('q')),
            'types'      => $this->types,
            'canManage'  => Permissions::canManage($this->auth->user(), $collection),
            'flash'      => Request::fromGlobals()->query('msg'),
        ]);
    }

    private function entryForm(string $handle, ?int $id): Response
    {
        $collection = $this->mustFind($handle);
        $this->requireManage($collection);
        $entry = $id !== null ? $this->entries->find($collection->id, $id) : null;
        if ($id !== null && $entry === null) {
            return $this->redirect("/admin/collections/{$handle}/entries");
        }
        return $this->renderEntryForm($collection, $this->modelFromEntry($collection, $entry), []);
    }

    private function entryStore(string $handle): Response
    {
        $collection = $this->mustFind($handle);
        $this->requireManage($collection);
        $req = Request::fromGlobals();
        $this->requireCsrf($req);
        return $this->saveEntry($collection, $req, null);
    }

    private function entryUpdate(string $handle, int $id): Response
    {
        $collection = $this->mustFind($handle);
        $this->requireManage($collection);
        $req = Request::fromGlobals();
        $this->requireCsrf($req);

        if ($this->entries->find($collection->id, $id) === null) {
            return $this->redirect("/admin/collections/{$handle}/entries");
        }
        return $this->saveEntry($collection, $req, $id);
    }

    /** Read request -> input object -> EntryService; render errors or redirect. */
    private function saveEntry(Collection $collection, Request $req, ?int $id): Response
    {
        $input  = $this->inputFromRequest($collection, $req);
        $result = $this->entryService->save($collection, $input, $id, $this->auth->user()?->id);

        if (!$result->successful) {
            return $this->renderEntryForm($collection, $this->modelFromInput($input, $id), $result->errors);
        }
        $msg = $id === null ? 'created' : ($collection->isSingle() ? 'saved' : 'updated');
        return $this->redirect("/admin/collections/{$collection->handle}/entries?msg={$msg}");
    }

    private function entryDestroy(string $handle, int $id): Response
    {
        $collection = $this->mustFind($handle);
        $this->requireManage($collection);
        $this->requireCsrf(Request::fromGlobals());
        // Singletons aren't deleted as entries — there's always exactly one.
        if ($collection->isSingle() || $this->entries->find($collection->id, $id) === null) {
            return $this->redirect("/admin/collections/{$handle}/entries");
        }
        $this->entryService->delete($collection, $id);
        return $this->redirect("/admin/collections/{$handle}/entries?msg=deleted");
    }

    // =============================================================== helpers

    private function renderEntryForm(Collection $collection, array $model, array $errors, ?string $flash = null): Response
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

    /** Editing/new: build the form model from a stored entry (or field defaults). */
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
            $values[$field->handle] = $this->types->get($field->type)->normalize($raw);
        }
        return new EntryInput(
            trim((string) $req->input('title')),
            trim((string) $req->input('slug')),
            in_array($req->input('status'), ['draft', 'published'], true) ? (string) $req->input('status') : 'draft',
            $values,
        );
    }

    /** Re-render the form after a failed save, preserving what the user typed. */
    private function modelFromInput(EntryInput $input, ?int $id): array
    {
        return ['id' => $id, 'title' => $input->title, 'slug' => $input->slug, 'status' => $input->status, 'values' => $input->values];
    }

    /**
     * @return array<int,array{handle:string,label:string,type:string,required:bool,options:array}>
     */
    private function fieldDefs(Request $req): array
    {
        $defs   = [];
        $fields = $req->all()['fields'] ?? [];
        if (!is_array($fields)) {
            return $defs;
        }
        foreach ($fields as $row) {
            if (!is_array($row)) {
                continue;
            }
            $label = trim((string) ($row['label'] ?? ''));
            if ($label === '') {
                continue;
            }
            $type   = ($row['type'] ?? 'text');
            $type   = $this->types->has($type) ? $type : 'text';
            $handle = Str::handle(($row['handle'] ?? '') !== '' ? $row['handle'] : $label);

            $options = [];
            if ($this->types->get($type)->hasChoices()) {
                $choices = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', (string) ($row['choices'] ?? '')) ?: [])));
                if ($choices !== []) {
                    $options['choices'] = $choices;
                }
            }
            foreach (['default', 'placeholder', 'help'] as $opt) {
                $val = trim((string) ($row[$opt] ?? ''));
                if ($val !== '') {
                    $options[$opt] = $val;
                }
            }
            if ($type === 'relation') {
                $options['target']   = trim((string) ($row['target'] ?? ''));
                $options['multiple'] = !empty($row['multiple']);
            }
            $defs[] = ['handle' => $handle, 'label' => $label, 'type' => $type, 'required' => !empty($row['required']), 'options' => $options];
        }
        return $defs;
    }

    /** @return array<string,mixed> collection options (kind + permissions) */
    private function options(Request $req): array
    {
        $roles = $req->all()['roles'] ?? [];
        $roles = is_array($roles) ? array_values(array_intersect(Permissions::ROLES, $roles)) : [];
        $kind  = $req->input('kind') === 'single' ? 'single' : 'collection';
        return ['kind' => $kind, 'permissions' => ['manage' => $roles]];
    }

    private function icon(Request $req): string
    {
        $icon = trim((string) $req->input('icon'));
        return $icon !== '' ? mb_substr($icon, 0, 4) : '❑';
    }

    /** @return string[] field types that use the choices builder */
    private function choiceTypes(): array
    {
        $out = [];
        foreach (array_keys($this->types->choices()) as $type) {
            if ($this->types->get($type)->hasChoices()) {
                $out[] = $type;
            }
        }
        return $out;
    }

    private function mustFind(string $handle): Collection
    {
        $collection = $this->collections->findByHandle($handle);
        if ($collection === null) {
            $this->abortTo('/admin/collections');
        }
        return $collection;
    }

    private function requireAdmin(): void
    {
        if (!Permissions::isAdmin($this->auth->user())) {
            $this->abortTo('/admin/collections');
        }
    }

    private function requireManage(Collection $collection): void
    {
        if (!Permissions::canManage($this->auth->user(), $collection)) {
            $this->abortTo("/admin/collections/{$collection->handle}/entries");
        }
    }

    private function requireCsrf(Request $req): void
    {
        if (!Csrf::check($req->input('_token'))) {
            $this->abortTo('/admin/collections');
        }
    }
}

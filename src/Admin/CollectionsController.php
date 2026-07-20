<?php

declare(strict_types=1);

namespace Nimbus\Admin;

use Nimbus\Content\Collection;
use Nimbus\Content\CollectionRepository;
use Nimbus\Content\EntryRepository;
use Nimbus\Content\FieldTypeRegistry;
use Nimbus\Content\Permissions;
use Nimbus\Http\Csrf;
use Nimbus\Http\Request;
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
    private FieldTypeRegistry $types;

    public function boot(): void
    {
        $this->collections = new CollectionRepository($this->db);
        $this->entries     = new EntryRepository($this->db);
        $this->types       = new FieldTypeRegistry();
    }

    public function routes(Router $r): void
    {
        $this->boot();

        // ---- collections (structural: admin only) ----
        $r->get('/admin/collections', fn (): string => $this->index());
        $r->get('/admin/collections/new', fn (): string => $this->form(null));
        $r->post('/admin/collections', fn (): string => $this->store());
        $r->get('/admin/collections/{id}/edit', fn (array $p): string => $this->form((int) $p['id']));
        $r->post('/admin/collections/{id}', fn (array $p): string => $this->update((int) $p['id']));
        $r->post('/admin/collections/{id}/delete', fn (array $p): string => $this->destroy((int) $p['id']));

        // ---- entries ----
        $r->get('/admin/collections/{handle}/entries', fn (array $p): string => $this->entriesIndex($p['handle']));
        $r->get('/admin/collections/{handle}/entries/new', fn (array $p): string => $this->entryForm($p['handle'], null));
        $r->post('/admin/collections/{handle}/entries', fn (array $p): string => $this->entryStore($p['handle']));
        $r->get('/admin/collections/{handle}/entries/{id}/edit', fn (array $p): string => $this->entryForm($p['handle'], (int) $p['id']));
        $r->post('/admin/collections/{handle}/entries/{id}', fn (array $p): string => $this->entryUpdate($p['handle'], (int) $p['id']));
        $r->post('/admin/collections/{handle}/entries/{id}/delete', fn (array $p): string => $this->entryDestroy($p['handle'], (int) $p['id']));
    }

    // =========================================================== collections

    private function index(): string
    {
        $this->guard();
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

    private function form(?int $id): string
    {
        $this->requireAdmin();
        $collection = $id !== null ? $this->collections->find($id) : null;
        if ($id !== null && $collection === null) {
            $this->redirect('/admin/collections');
        }
        return $this->page('collections/form', 'collections', [
            'collection' => $collection,
            'typeChoices' => $this->types->choices(),
            'choiceTypes' => $this->choiceTypes(),
            'roles'      => Permissions::ROLES,
            'csrf'       => Csrf::token(),
        ]);
    }

    private function store(): string
    {
        $this->requireAdmin();
        $req = Request::fromGlobals();
        $this->requireCsrf($req);

        $name   = trim((string) $req->input('name'));
        $handle = Str::handle($req->input('handle') ?: $name);
        if ($name === '' || $handle === '' || $this->collections->handleExists($handle)) {
            $this->redirect('/admin/collections/new');
        }

        $id = $this->collections->create($handle, $name, $this->icon($req), (string) $req->input('description'), $this->options($req));
        $this->collections->syncFields($id, $this->fieldDefs($req));
        $this->redirect('/admin/collections?msg=created');
    }

    private function update(int $id): string
    {
        $this->requireAdmin();
        $req = Request::fromGlobals();
        $this->requireCsrf($req);

        if ($this->collections->find($id) === null) {
            $this->redirect('/admin/collections');
        }
        $name = trim((string) $req->input('name'));
        if ($name === '') {
            $this->redirect("/admin/collections/{$id}/edit");
        }
        $this->collections->update($id, $name, $this->icon($req), (string) $req->input('description'), $this->options($req));
        $this->collections->syncFields($id, $this->fieldDefs($req));
        $this->redirect('/admin/collections?msg=updated');
    }

    private function destroy(int $id): string
    {
        $this->requireAdmin();
        $this->requireCsrf(Request::fromGlobals());
        $this->collections->delete($id);
        $this->redirect('/admin/collections?msg=deleted');
    }

    // =============================================================== entries

    private function entriesIndex(string $handle): string
    {
        $collection = $this->mustFind($handle);
        $this->guard();

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

    private function entryForm(string $handle, ?int $id): string
    {
        $collection = $this->mustFind($handle);
        $this->requireManage($collection);
        $entry = $id !== null ? $this->entries->find($collection->id, $id) : null;
        if ($id !== null && $entry === null) {
            $this->redirect("/admin/collections/{$handle}/entries");
        }
        return $this->renderEntryForm($collection, $this->modelFromEntry($collection, $entry), []);
    }

    private function entryStore(string $handle): string
    {
        $collection = $this->mustFind($handle);
        $this->requireManage($collection);
        $req = Request::fromGlobals();
        $this->requireCsrf($req);

        // Singletons never create a second entry: update the existing one if present.
        $id = null;
        if ($collection->isSingle()) {
            $existing = $this->entries->firstForCollection($collection->id);
            $id = $existing !== null ? (int) $existing['id'] : null;
        }
        return $this->saveEntry($collection, $req, $id);
    }

    private function entryUpdate(string $handle, int $id): string
    {
        $collection = $this->mustFind($handle);
        $this->requireManage($collection);
        $req = Request::fromGlobals();
        $this->requireCsrf($req);

        if ($this->entries->find($collection->id, $id) === null) {
            $this->redirect("/admin/collections/{$handle}/entries");
        }
        return $this->saveEntry($collection, $req, $id);
    }

    /** Shared create/update: validate, and on failure re-render with errors. */
    private function saveEntry(Collection $collection, Request $req, ?int $id): string
    {
        $model  = $this->modelFromRequest($collection, $req, $id);
        $errors = $this->validate($collection, $model);
        if ($errors !== []) {
            return $this->renderEntryForm($collection, $model, $errors);
        }
        $attrs = $this->attrsFromModel($collection, $model);
        if ($id === null) {
            $this->entries->create($collection->id, $attrs, $this->auth->user()?->id);
            $msg = 'created';
        } else {
            $this->entries->update($collection->id, $id, $attrs);
            $msg = $collection->isSingle() ? 'saved' : 'updated';
        }
        $this->redirect("/admin/collections/{$collection->handle}/entries?msg={$msg}");
    }

    private function entryDestroy(string $handle, int $id): string
    {
        $collection = $this->mustFind($handle);
        $this->requireManage($collection);
        $this->requireCsrf(Request::fromGlobals());
        $this->entries->delete($collection->id, $id);
        $this->redirect("/admin/collections/{$handle}/entries?msg=deleted");
    }

    // =============================================================== helpers

    private function renderEntryForm(Collection $collection, array $model, array $errors, ?string $flash = null): string
    {
        return $this->page('entries/form', 'collections', [
            'collection' => $collection,
            'model'      => $model,
            'errors'     => $errors,
            'flash'      => $flash,
            'types'      => $this->types,
            'csrf'       => Csrf::token(),
        ]);
    }

    /** Editing/new: build the form model from a stored entry (or field defaults). */
    private function modelFromEntry(Collection $collection, ?array $entry): array
    {
        if ($entry !== null) {
            return [
                'id'     => (int) $entry['id'],
                'title'  => (string) $entry['title'],
                'slug'   => (string) $entry['slug'],
                'status' => (string) $entry['status'],
                'values' => is_array($entry['data']) ? $entry['data'] : [],
            ];
        }
        $values = [];
        foreach ($collection->fields as $field) {
            $values[$field->handle] = $field->option('default', '');
        }
        return ['id' => null, 'title' => '', 'slug' => '', 'status' => 'draft', 'values' => $values];
    }

    /** After a submit: build the form model from request input (normalized). */
    private function modelFromRequest(Collection $collection, Request $req, ?int $id): array
    {
        $posted = $req->all()['f'] ?? [];
        $values = [];
        foreach ($collection->fields as $field) {
            $raw = is_array($posted) ? ($posted[$field->handle] ?? null) : null;
            $values[$field->handle] = $this->types->get($field->type)->normalize($raw);
        }
        return [
            'id'     => $id,
            'title'  => trim((string) $req->input('title')),
            'slug'   => trim((string) $req->input('slug')),
            'status' => in_array($req->input('status'), ['draft', 'published'], true) ? (string) $req->input('status') : 'draft',
            'values' => $values,
        ];
    }

    /** @return array<string,string> validation errors (title + fields) */
    private function validate(Collection $collection, array $model): array
    {
        $errors = (new \Nimbus\Content\Validator($this->types))->validate($collection, $model['values']);
        if ($model['title'] === '') {
            $errors['__title'] = 'Title is required.';
        }
        return $errors;
    }

    /** @return array{title:string,slug:string,status:string,data:array<string,mixed>} */
    private function attrsFromModel(Collection $collection, array $model): array
    {
        $title = $model['title'] !== '' ? $model['title'] : 'Untitled';
        $slug  = Str::slug($model['slug'] !== '' ? $model['slug'] : $title) ?: 'entry';
        $base  = $slug;
        $n     = 2;
        while ($this->entries->slugExists($collection->id, $slug, $model['id'] ?? 0)) {
            $slug = $base . '-' . $n++;
        }
        return ['title' => $title, 'slug' => $slug, 'status' => $model['status'], 'data' => $model['values']];
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
            $this->redirect('/admin/collections');
        }
        return $collection;
    }

    private function requireAdmin(): void
    {
        $this->guard();
        if (!Permissions::isAdmin($this->auth->user())) {
            $this->redirect('/admin/collections');
        }
    }

    private function requireManage(Collection $collection): void
    {
        $this->guard();
        if (!Permissions::canManage($this->auth->user(), $collection)) {
            $this->redirect("/admin/collections/{$collection->handle}/entries");
        }
    }

    private function requireCsrf(Request $req): void
    {
        if (!Csrf::check($req->input('_token'))) {
            $this->redirect('/admin/collections');
        }
    }
}

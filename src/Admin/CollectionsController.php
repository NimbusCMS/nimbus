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
        return $this->page('entries/form', 'collections', [
            'collection' => $collection,
            'entry'      => $entry,
            'types'      => $this->types,
            'csrf'       => Csrf::token(),
        ]);
    }

    private function entryStore(string $handle): string
    {
        $collection = $this->mustFind($handle);
        $this->requireManage($collection);
        $req = Request::fromGlobals();
        $this->requireCsrf($req);

        $attrs = $this->entryAttrs($collection, $req);
        $this->entries->create($collection->id, $attrs, $this->auth->user()?->id);
        $this->redirect("/admin/collections/{$handle}/entries?msg=created");
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
        $this->entries->update($collection->id, $id, $this->entryAttrs($collection, $req, $id));
        $this->redirect("/admin/collections/{$handle}/entries?msg=updated");
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

    /** @return array{title:string,slug:string,status:string,data:array<string,mixed>} */
    private function entryAttrs(Collection $collection, Request $req, int $exceptId = 0): array
    {
        $title  = trim((string) $req->input('title')) ?: 'Untitled';
        $status = in_array($req->input('status'), ['draft', 'published'], true) ? (string) $req->input('status') : 'draft';

        $slug = Str::slug($req->input('slug') ?: $title) ?: 'entry';
        $base = $slug;
        $n    = 2;
        while ($this->entries->slugExists($collection->id, $slug, $exceptId)) {
            $slug = $base . '-' . $n++;
        }

        $posted = $req->all()['f'] ?? [];
        $data   = [];
        foreach ($collection->fields as $field) {
            $raw = is_array($posted) ? ($posted[$field->handle] ?? null) : null;
            $data[$field->handle] = $this->types->get($field->type)->normalize($raw);
        }

        return ['title' => $title, 'slug' => $slug, 'status' => $status, 'data' => $data];
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
            $defs[] = ['handle' => $handle, 'label' => $label, 'type' => $type, 'required' => !empty($row['required']), 'options' => $options];
        }
        return $defs;
    }

    /** @return array<string,mixed> collection options (permissions) */
    private function options(Request $req): array
    {
        $roles = $req->all()['roles'] ?? [];
        $roles = is_array($roles) ? array_values(array_intersect(Permissions::ROLES, $roles)) : [];
        return ['permissions' => ['manage' => $roles]];
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

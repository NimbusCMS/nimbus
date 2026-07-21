<?php

declare(strict_types=1);

namespace Nimbus\Admin;

use Nimbus\Content\Collection;
use Nimbus\Content\CollectionRepository;
use Nimbus\Content\CollectionService;
use Nimbus\Content\DuplicateHandle;
use Nimbus\Content\EntryInput;
use Nimbus\Content\Field;
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
            $g->get('', fn (Request $req, array $p): Response => $this->index($req))->name('admin.collections.index');
            $g->get('/new', fn (Request $req, array $p): Response => $this->form(null))->name('admin.collections.new');
            $g->post('', fn (Request $req, array $p): Response => $this->store($req));
            $g->get('/{id}/edit', fn (Request $req, array $p): Response => $this->form((int) $p['id']))->name('admin.collections.edit');
            $g->post('/{id}', fn (Request $req, array $p): Response => $this->update($req, (int) $p['id']));
            $g->post('/{id}/delete', fn (Request $req, array $p): Response => $this->destroy($req, (int) $p['id']));

            // ---- entries ----
            $g->get('/{handle}/entries', fn (Request $req, array $p): Response => $this->entriesIndex($req, $p['handle']))->name('admin.entries.index');
            $g->get('/{handle}/entries/new', fn (Request $req, array $p): Response => $this->entryForm($p['handle'], null))->name('admin.entries.new');
            $g->post('/{handle}/entries', fn (Request $req, array $p): Response => $this->entryStore($req, $p['handle']));
            $g->get('/{handle}/entries/{id}/edit', fn (Request $req, array $p): Response => $this->entryForm($p['handle'], (int) $p['id']))->name('admin.entries.edit');
            $g->post('/{handle}/entries/{id}', fn (Request $req, array $p): Response => $this->entryUpdate($req, $p['handle'], (int) $p['id']));
            $g->post('/{handle}/entries/{id}/delete', fn (Request $req, array $p): Response => $this->entryDestroy($req, $p['handle'], (int) $p['id']));
        });
    }

    // =========================================================== collections

    private function index(Request $req): Response
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
            'flash'   => $req->query('msg'),
        ]);
    }

    private function form(?int $id): Response
    {
        $this->requireAdmin();
        $collection = $id !== null ? $this->collections->find($id) : null;
        if ($id !== null && $collection === null) {
            return $this->redirect('/admin/collections');
        }
        return $this->renderCollectionForm($collection, $this->draftFromCollection($collection), []);
    }

    private function store(Request $req): Response
    {
        $this->requireAdmin();
        $this->requireCsrf($req);

        $draft  = $this->draftFromRequest($req);
        $errors = $this->validateDraft($draft);

        if ($errors === []) {
            try {
                $this->collectionService->create(
                    $draft['handle'],
                    $draft['name'],
                    $draft['icon'],
                    $draft['description'],
                    $this->options($req),
                    $this->fieldDefs($req),
                );
                return $this->redirect('/admin/collections?msg=created');
            } catch (DuplicateHandle $e) {
                $errors['handle'] = 'The handle “' . $e->handle . '” is already taken. Pick another.';
            }
        }
        // Re-render what was submitted rather than throwing the work away.
        return $this->renderCollectionForm(null, $draft, $errors);
    }

    private function update(Request $req, int $id): Response
    {
        $this->requireAdmin();
        $this->requireCsrf($req);

        $collection = $this->collections->find($id);
        if ($collection === null) {
            return $this->redirect('/admin/collections');
        }

        $draft  = $this->draftFromRequest($req);
        $errors = $this->validateDraft($draft);
        if ($errors !== []) {
            return $this->renderCollectionForm($collection, $draft, $errors);
        }

        $this->collectionService->update($id, $draft['name'], $draft['icon'], $draft['description'], $this->options($req), $this->fieldDefs($req));
        return $this->redirect('/admin/collections?msg=updated');
    }

    /**
     * @param array<string,mixed>  $draft
     * @param array<string,string> $errors
     */
    private function renderCollectionForm(?Collection $collection, array $draft, array $errors): Response
    {
        $collectionOptions = [];
        foreach ($this->collections->all() as $c) {
            $collectionOptions[$c->handle] = $c->name;
        }
        return $this->page('collections/form', 'collections', [
            'collection'        => $collection,
            'draft'             => $draft,
            'errors'            => $errors,
            'typeChoices'       => $this->types->choices(),
            'choiceTypes'       => $this->choiceTypes(),
            'relationTypes'     => ['relation'],
            'collectionOptions' => $collectionOptions,
            'roles'             => Permissions::ROLES,
            'csrf'              => Csrf::token(),
        ]);
    }

    /** @return array<string,mixed> the form model: stored collection, or blank for a new one */
    private function draftFromCollection(?Collection $c): array
    {
        if ($c === null) {
            return ['name' => '', 'handle' => '', 'icon' => '❑', 'description' => '', 'kind' => 'collection', 'roles' => [], 'fields' => []];
        }
        return [
            'name'        => $c->name,
            'handle'      => $c->handle,
            'icon'        => $c->icon,
            'description' => $c->description,
            'kind'        => $c->isSingle() ? 'single' : 'collection',
            'roles'       => $c->managerRoles(),
            'fields'      => $c->fields,
        ];
    }

    /** @return array<string,mixed> the form model rebuilt from a submission */
    private function draftFromRequest(Request $req): array
    {
        $name    = trim((string) $req->input('name'));
        $options = $this->options($req);

        return [
            'name'        => $name,
            'handle'      => Str::handle(($req->input('handle') ?? '') !== '' ? (string) $req->input('handle') : $name),
            'icon'        => $this->icon($req),
            'description' => (string) $req->input('description'),
            'kind'        => $options['kind'],
            'roles'       => $options['permissions']['manage'],
            // Field defs are already normalized; wrap them so the builder can
            // re-render the rows exactly as they were submitted.
            'fields'      => array_map(
                static fn (array $d): Field => new Field($d['handle'], $d['label'], $d['type'], $d['required'], $d['options']),
                $this->fieldDefs($req),
            ),
        ];
    }

    /**
     * @param array<string,mixed> $draft
     * @return array<string,string>
     */
    private function validateDraft(array $draft): array
    {
        $errors = [];
        if ($draft['name'] === '') {
            $errors['name'] = 'Name is required.';
        }
        if ($draft['handle'] === '') {
            $errors['handle'] = 'Handle is required (it is normally derived from the name).';
        }
        return $errors;
    }

    private function destroy(Request $req, int $id): Response
    {
        $this->requireAdmin();
        $this->requireCsrf($req);
        $this->collectionService->delete($id);
        return $this->redirect('/admin/collections?msg=deleted');
    }

    // =============================================================== entries

    private function entriesIndex(Request $req, string $handle): Response
    {
        $collection = $this->mustFind($handle);

        // A singleton has no list — go straight to editing its one entry.
        if ($collection->isSingle()) {
            $this->requireManage($collection);
            $entry = $this->entries->firstForCollection($collection->id);
            return $this->renderEntryForm($collection, $this->modelFromEntry($collection, $entry), [], $req->query('msg'));
        }

        return $this->page('entries/index', 'collections', [
            'collection' => $collection,
            'rows'       => $this->entries->forCollection($collection->id, $req->query('q')),
            'types'      => $this->types,
            'canManage'  => Permissions::canManage($this->auth->user(), $collection),
            'flash'      => $req->query('msg'),
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

    private function entryStore(Request $req, string $handle): Response
    {
        $collection = $this->mustFind($handle);
        $this->requireManage($collection);
        $this->requireCsrf($req);
        return $this->saveEntry($collection, $req, null);
    }

    private function entryUpdate(Request $req, string $handle, int $id): Response
    {
        $collection = $this->mustFind($handle);
        $this->requireManage($collection);
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

    private function entryDestroy(Request $req, string $handle, int $id): Response
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

    // =============================================================== helpers

    /**
     * @param array<string,mixed>  $model
     * @param array<string,string> $errors
     */
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

    /** @return array<int,FieldDef> */
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

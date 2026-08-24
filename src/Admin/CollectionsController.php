<?php

declare(strict_types=1);

namespace Nimbus\Admin;

use Nimbus\Auth\Auth;
use Nimbus\Content\Collection;
use Nimbus\Content\CollectionInUse;
use Nimbus\Content\CollectionRepository;
use Nimbus\Content\CollectionService;
use Nimbus\Content\DuplicateHandle;
use Nimbus\Content\Field;
use Nimbus\Content\FieldTypeRegistry;
use Nimbus\Content\ReservedHandle;
use Nimbus\Database\Connection;
use Nimbus\Http\Csrf;
use Nimbus\Http\Request;
use Nimbus\Http\Response;
use Nimbus\Http\Router;
use Nimbus\Http\Url;
use Nimbus\Settings\Settings;
use Nimbus\Support\Str;

/**
 * Administering the *shape* of content: defining collections, their fields and
 * who may manage them. Admin-only, because every action here changes the schema
 * that existing entries are interpreted against.
 *
 * Writing content lives in EntriesController. The field builder offers types
 * from the FieldTypeRegistry, which is also where plugins add their own.
 */
final class CollectionsController extends Controller
{
    /** ADMIN-10: post-redirect notices are fixed CODE→string maps (never URL text). */
    private const OK_NOTICES = [
        'created' => 'Collection created.',
        'updated' => 'Collection updated.',
        'deleted' => 'Collection deleted.',
    ];

    private CollectionRepository $collections;
    private CollectionService $collectionService;

    /** $fieldTypes is the application's single registry — never a local one. */
    public function __construct(Connection $db, Auth $auth, Settings $settings, private FieldTypeRegistry $types, ?AdminPageRegistry $adminPages = null)
    {
        parent::__construct($db, $auth, $settings, $adminPages);
        $this->collections       = new CollectionRepository($this->db);
        $this->collectionService = new CollectionService($this->db, $this->collections);
    }

    public function routes(Router $r): void
    {
        $r->group('/admin/collections', [$this->authMw], function (Router $g): void {
            $g->get('', fn (Request $req, array $p): Response => $this->index($req))->name('admin.collections.index');
            $g->get('/new', fn (Request $req, array $p): Response => $this->form(null))->name('admin.collections.new');
            $g->post('', fn (Request $req, array $p): Response => $this->store($req));
            $g->get('/{id}/edit', fn (Request $req, array $p): Response => $this->form((int) $p['id']))->name('admin.collections.edit');
            $g->post('/{id}', fn (Request $req, array $p): Response => $this->update($req, (int) $p['id']));
            $g->post('/{id}/delete', fn (Request $req, array $p): Response => $this->destroy($req, (int) $p['id']));
        });
    }

    /** $error is a server-built failure message (escaped by the view, never from
     *  the URL — ADMIN-10 discipline); null on a normal GET. */
    private function index(Request $req, ?string $error = null): Response
    {
        // Counts for every collection in two grouped queries, not one pair per
        // collection (no N+1); a collection missing from a map has zero.
        $fieldCounts = $this->collections->fieldCounts();
        $entryCounts = $this->collections->entryCounts();
        $rows        = [];
        foreach ($this->collections->all() as $c) {
            // Only list collections the user can read (ADR 0011): an out-of-scope
            // collection is not enumerated here, just as it 404-equivalents on a
            // direct hit. Counts follow the filtered rows, so nothing leaks.
            if (!$this->gate->reads($c)) {
                continue;
            }
            $rows[] = [
                'c'       => $c,
                'fields'  => $fieldCounts[$c->id] ?? 0,
                'entries' => $entryCounts[$c->id] ?? 0,
                'manage'  => $this->gate->manages($c),
            ];
        }
        return $this->page('collections/index', 'collections', [
            'rows'    => $rows,
            'isAdmin' => $this->gate->can('schema', 'write'),
            'notice'  => $this->notice($req, self::OK_NOTICES, []),
            'error'   => $error,
        ]);
    }

    private function form(?int $id): Response
    {
        $this->requireCan('schema', 'write', Url::to('admin.collections.index'));
        $collection = $id !== null ? $this->collections->find($id) : null;
        if ($id !== null && $collection === null) {
            return $this->redirect(Url::to('admin.collections.index'));
        }
        return $this->renderCollectionForm($collection, $this->draftFromCollection($collection), []);
    }

    private function store(Request $req): Response
    {
        $this->requireCan('schema', 'write', Url::to('admin.collections.index'));
        $this->requireCsrf($req);

        $defs   = $this->fieldDefs($req);
        $draft  = $this->draftFromRequest($req, $defs);
        $errors = $this->validateDraft($draft);

        if ($errors === []) {
            try {
                $this->collectionService->create(
                    $draft['handle'],
                    $draft['name'],
                    $draft['icon'],
                    $draft['description'],
                    $this->options($req),
                    $defs,
                );
                return $this->redirect(Url::to('admin.collections.index') . '?msg=created');
            } catch (DuplicateHandle $e) {
                $errors['handle'] = 'The handle “' . $e->handle . '” is already taken. Pick another.';
            } catch (ReservedHandle $e) {
                $errors['handle'] = $this->reservedMessage($e);
            }
        }
        // Re-render what was submitted rather than throwing the work away.
        return $this->renderCollectionForm(null, $draft, $errors);
    }

    private function update(Request $req, int $id): Response
    {
        $this->requireCan('schema', 'write', Url::to('admin.collections.index'));
        $this->requireCsrf($req);

        $collection = $this->collections->find($id);
        if ($collection === null) {
            return $this->redirect(Url::to('admin.collections.index'));
        }

        $defs   = $this->fieldDefs($req);
        $draft  = $this->draftFromRequest($req, $defs);
        $errors = $this->validateDraft($draft);
        if ($errors !== []) {
            return $this->renderCollectionForm($collection, $draft, $errors);
        }

        try {
            $this->collectionService->update($id, $draft['name'], $draft['icon'], $draft['description'], $this->options($req), $defs);
        } catch (ReservedHandle $e) {
            // Only a *new* reserved field handle reaches here (grandfathered
            // fields are allowed through) — collection handles are immutable.
            $errors['handle'] = $this->reservedMessage($e);
            return $this->renderCollectionForm($collection, $draft, $errors);
        }
        return $this->redirect(Url::to('admin.collections.index') . '?msg=updated');
    }

    private function reservedMessage(ReservedHandle $e): string
    {
        return $e->kind === 'collection'
            ? 'The handle “' . $e->handle . '” is reserved — it collides with a built-in permission or route name. Pick another.'
            : 'The field handle “' . $e->handle . '” is reserved — title, slug and published_at are built-in entry attributes. Rename that field.';
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
            'roles'       => [],
            'fields'      => $c->fields,
        ];
    }

    /** @return array<string,mixed> the form model rebuilt from a submission */
    /**
     * @param array<int,array{handle:string,label:string,type:string,required:bool,options:array<string,mixed>}> $defs
     *        the field definitions, parsed once by the caller and passed to both
     *        validation and the service (no second parse that could differ)
     * @return array<string,mixed>
     */
    private function draftFromRequest(Request $req, array $defs): array
    {
        $name    = trim((string) $req->input('name'));
        $options = $this->options($req);

        return [
            'name'        => $name,
            'handle'      => Str::handle(($req->input('handle') ?? '') !== '' ? (string) $req->input('handle') : $name),
            'icon'        => $this->icon($req),
            'description' => (string) $req->input('description'),
            'kind'        => $options['kind'],
            'roles'       => [],
            // Wrap the same defs so the builder re-renders the rows exactly as submitted.
            'fields'      => array_map(
                static fn (array $d): Field => new Field($d['handle'], $d['label'], $d['type'], $d['required'], $d['options']),
                $defs,
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
        } elseif ($this->tooLong($draft['name'], CollectionService::NAME_MAX)) {
            $errors['name'] = 'Name must be ' . CollectionService::NAME_MAX . ' characters or fewer.';
        }
        if ($draft['handle'] === '') {
            $errors['handle'] = 'Handle is required (it is normally derived from the name).';
        } elseif ($this->tooLong($draft['handle'], CollectionService::HANDLE_MAX)) {
            // The handle derives from the name and is not truncated — the column is
            // VARCHAR(80), so a long name would 1406 → 500 without this.
            $errors['handle'] = 'Handle must be ' . CollectionService::HANDLE_MAX . ' characters or fewer — shorten the name or set an explicit handle.';
        }
        if ($this->tooLong($draft['description'], CollectionService::DESC_MAX)) {
            $errors['description'] = 'Description must be ' . CollectionService::DESC_MAX . ' characters or fewer.';
        }

        // Per-field: label/handle length and duplicate handles. Intra-submission
        // and over the *normalized* handles (the edit form re-submits every field,
        // so a collision — silent-overwrite or 500 — always appears as two rows
        // here). Keyed `fields.{i}` to render on the offending row.
        // Existing collection handles, for validating a relation field's target
        // server-side (ADMIN-14a): the form offers a dropdown of real handles, but
        // the server must reject a crafted/blank/deleted target rather than store
        // it — a bogus target silently yields an empty picker and dead relations.
        // On create the new collection isn't in this set yet, so a self-target is
        // rejected (matching the dropdown's own limitation); on edit it is present.
        $handles = [];
        foreach ($this->collections->all() as $c) {
            $handles[$c->handle] = true;
        }

        $seen = [];
        foreach ($draft['fields'] as $i => $field) {
            $target = $field->type === 'relation' ? (string) $field->option('target', '') : '';
            if ($this->tooLong($field->label, CollectionService::LABEL_MAX)) {
                $errors["fields.$i"] = 'Field label must be ' . CollectionService::LABEL_MAX . ' characters or fewer.';
            } elseif ($this->tooLong($field->handle, CollectionService::HANDLE_MAX)) {
                $errors["fields.$i"] = 'Field handle must be ' . CollectionService::HANDLE_MAX . ' characters or fewer — it derives from the label.';
            } elseif ($field->handle !== '' && isset($seen[$field->handle])) {
                $errors["fields.$i"] = 'Two fields resolve to the same handle “' . $field->handle . '” — rename one or give it a distinct handle.';
            } elseif ($field->type === 'relation' && !isset($handles[$target])) {
                $errors["fields.$i"] = $target === ''
                    ? 'Choose a target collection for this relation field.'
                    : 'That relation field points at a collection that does not exist.';
            }
            if ($field->handle !== '') {
                $seen[$field->handle] = true;
            }
        }
        return $errors;
    }

    private function destroy(Request $req, int $id): Response
    {
        $this->requireCan('schema', 'write', Url::to('admin.collections.index'));
        $this->requireCsrf($req);
        try {
            $this->collectionService->delete($id);
        } catch (CollectionInUse $e) {
            // Server-render the detail (it names operator-authored field labels),
            // escaped — never round-tripped through the URL (ADMIN-10).
            return $this->index($req, $e->getMessage());
        }
        return $this->redirect(Url::to('admin.collections.index') . '?msg=deleted');
    }

    // =============================================================== helpers

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
            // Coerce nested values — a crafted `fields[0][type][]`/`[handle][]`
            // sends an array where a string is expected, which would TypeError
            // through has()/Str::handle() (ADMIN-12).
            $type      = is_string($row['type'] ?? null) ? $row['type'] : 'text';
            $type      = $this->types->has($type) ? $type : 'text';
            $handleRaw = is_string($row['handle'] ?? null) ? $row['handle'] : '';
            $handle    = Str::handle($handleRaw !== '' ? $handleRaw : $label);

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
                $options['target']   = is_string($row['target'] ?? null) ? trim($row['target']) : '';
                $options['multiple'] = !empty($row['multiple']);
            }
            $defs[] = ['handle' => $handle, 'label' => $label, 'type' => $type, 'required' => !empty($row['required']), 'options' => $options];
        }
        return $defs;
    }

    /**
     * Collection options (kind). The legacy per-collection manage-list is no
     * longer written — which roles may manage a collection is now a capability
     * on the role (ADR 0011), granted from the Roles page. `manage` stays as an
     * empty array for shape compatibility with older rows.
     *
     * @return array<string,mixed>
     */
    private function options(Request $req): array
    {
        $kind = $req->input('kind') === 'single' ? 'single' : 'collection';
        return ['kind' => $kind, 'permissions' => ['manage' => []]];
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


}

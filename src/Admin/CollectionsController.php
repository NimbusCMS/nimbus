<?php

declare(strict_types=1);

namespace Nimbus\Admin;

use Nimbus\Auth\Auth;
use Nimbus\Content\Collection;
use Nimbus\Content\CollectionRepository;
use Nimbus\Content\CollectionService;
use Nimbus\Content\DuplicateHandle;
use Nimbus\Content\Field;
use Nimbus\Content\FieldTypeRegistry;
use Nimbus\Database\Connection;
use Nimbus\Http\Csrf;
use Nimbus\Http\Request;
use Nimbus\Http\Response;
use Nimbus\Http\Router;
use Nimbus\Http\Url;
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
    private CollectionRepository $collections;
    private CollectionService $collectionService;

    /** $fieldTypes is the application's single registry — never a local one. */
    public function __construct(Connection $db, Auth $auth, private FieldTypeRegistry $types, ?AdminPageRegistry $adminPages = null)
    {
        parent::__construct($db, $auth, $adminPages);
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

    private function index(Request $req): Response
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
            'flash'   => $req->query('msg'),
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
                return $this->redirect(Url::to('admin.collections.index') . '?msg=created');
            } catch (DuplicateHandle $e) {
                $errors['handle'] = 'The handle “' . $e->handle . '” is already taken. Pick another.';
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

        $draft  = $this->draftFromRequest($req);
        $errors = $this->validateDraft($draft);
        if ($errors !== []) {
            return $this->renderCollectionForm($collection, $draft, $errors);
        }

        $this->collectionService->update($id, $draft['name'], $draft['icon'], $draft['description'], $this->options($req), $this->fieldDefs($req));
        return $this->redirect(Url::to('admin.collections.index') . '?msg=updated');
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
            'roles'       => [],
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
        $this->requireCan('schema', 'write', Url::to('admin.collections.index'));
        $this->requireCsrf($req);
        $this->collectionService->delete($id);
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

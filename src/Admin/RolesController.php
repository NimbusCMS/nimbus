<?php

declare(strict_types=1);

namespace Nimbus\Admin;

use Nimbus\Auth\Auth;
use Nimbus\Auth\RoleRepository;
use Nimbus\Content\CollectionRepository;
use Nimbus\Database\Connection;
use Nimbus\Http\Csrf;
use Nimbus\Http\Request;
use Nimbus\Http\Response;
use Nimbus\Http\Router;
use Nimbus\Http\Url;

/**
 * Managing roles (ADR 0011): named bundles of capabilities an admin composes and
 * assigns to users (and tokens). Admin-only for now — the capability that gates
 * this page (`roles:write`) becomes load-bearing when enforcement flips in a
 * later slice; until then, only administrators reach it, so subset-only is
 * trivially satisfied.
 */
final class RolesController extends Controller
{
    /** The management capabilities offered in the checklist (label by capability). */
    private const MANAGEMENT = [
        'schema:write'   => 'Manage content types (collections & fields)',
        'media:read'     => 'View the media library',
        'media:write'    => 'Upload & delete media',
        'users:write'    => 'Manage users',
        'tokens:write'   => 'Manage API tokens',
        'roles:write'    => 'Manage roles',
        'settings:write' => 'Change settings',
    ];

    private RoleRepository $roles;
    private CollectionRepository $collections;

    public function __construct(Connection $db, Auth $auth, ?AdminPageRegistry $adminPages = null)
    {
        parent::__construct($db, $auth, $adminPages);
        $this->roles       = new RoleRepository($db);
        $this->collections = new CollectionRepository($db);
    }

    public function routes(Router $r): void
    {
        $r->group('/admin/roles', [$this->authMw], function (Router $g): void {
            $g->get('', fn (Request $req, array $p): Response => $this->index($req))->name('admin.roles.index');
            $g->post('', fn (Request $req, array $p): Response => $this->store($req));
            $g->post('/{id}', fn (Request $req, array $p): Response => $this->update($req, (int) $p['id']));
            $g->post('/{id}/delete', fn (Request $req, array $p): Response => $this->destroy($req, (int) $p['id']));
        });
    }

    private function index(Request $req): Response
    {
        $this->requireCan('roles', 'write');

        $editId  = $req->query('edit');
        $editing = $editId !== null && ctype_digit($editId) ? $this->roles->find((int) $editId) : null;

        return $this->page('roles/index', 'roles', [
            'roles'       => $this->roles->all(),
            'collections' => $this->collections->all(),
            'management'  => self::MANAGEMENT,
            'editing'     => $editing,
            'counts'      => $this->assignedCounts(),
            'flash'       => $req->query('msg'),
            'error'       => $req->query('err'),
            'csrf'        => Csrf::token(),
        ]);
    }

    private function store(Request $req): Response
    {
        $this->requireCan('roles', 'write');
        $this->requireCsrf($req, Url::to('admin.roles.index'));

        $name = trim((string) $req->input('name'));
        if ($name === '') {
            return $this->redirect(Url::to('admin.roles.index') . '?err=' . rawurlencode('A role needs a name.'));
        }
        if ($this->tooLong($name, 80)) { // nb_roles.name VARCHAR(80)
            return $this->redirect(Url::to('admin.roles.index') . '?err=' . rawurlencode('Role name must be 80 characters or fewer.'));
        }
        if ($this->roles->findByName($name) !== null) {
            return $this->redirect(Url::to('admin.roles.index') . '?err=' . rawurlencode("A role named \"{$name}\" already exists."));
        }

        $caps      = $this->capabilitiesFrom($req);
        $ungranted = $this->firstUnheld($caps);
        if ($ungranted !== null) {
            return $this->redirect(Url::to('admin.roles.index') . '?err=' . rawurlencode("You cannot grant a capability you do not hold: {$ungranted}."));
        }

        $this->roles->create($name, $caps, false);
        return $this->redirect(Url::to('admin.roles.index') . '?msg=created');
    }

    private function update(Request $req, int $id): Response
    {
        $this->requireCan('roles', 'write');
        $this->requireCsrf($req, Url::to('admin.roles.index'));

        $role = $this->roles->find($id);
        if ($role === null) {
            return $this->redirect(Url::to('admin.roles.index') . '?err=' . rawurlencode('No such role.'));
        }
        // The admin role is the built-in super-grant; keep it intact so an install
        // can never edit away its own administrator.
        if ($role->name === 'admin') {
            return $this->redirect(Url::to('admin.roles.index') . '?err=' . rawurlencode('The admin role cannot be edited.'));
        }
        // Subset-only, both ways: you cannot edit a role that already grants more
        // than you hold (no nerf-by-edit / no touching a superior role), and you
        // cannot grant a capability you do not hold.
        $existing = $this->firstUnheld($role->capabilities);
        if ($existing !== null) {
            return $this->redirect(Url::to('admin.roles.index') . '?err=' . rawurlencode("You cannot edit a role that grants a capability beyond your own: {$existing}."));
        }
        $caps      = $this->capabilitiesFrom($req);
        $ungranted = $this->firstUnheld($caps);
        if ($ungranted !== null) {
            return $this->redirect(Url::to('admin.roles.index') . '?err=' . rawurlencode("You cannot grant a capability you do not hold: {$ungranted}."));
        }

        $this->roles->setCapabilities($id, $caps);
        return $this->redirect(Url::to('admin.roles.index') . '?msg=updated');
    }

    /**
     * The first capability the acting user does not hold, or null (subset-only).
     *
     * @param list<string> $capabilities
     */
    private function firstUnheld(array $capabilities): ?string
    {
        foreach ($capabilities as $capability) {
            if (!$this->gate->holds((string) $capability)) {
                return (string) $capability;
            }
        }
        return null;
    }

    private function destroy(Request $req, int $id): Response
    {
        $this->requireCan('roles', 'write');
        $this->requireCsrf($req, Url::to('admin.roles.index'));

        $role = $this->roles->find($id);
        if ($role === null) {
            return $this->redirect(Url::to('admin.roles.index') . '?err=' . rawurlencode('No such role.'));
        }
        if ($role->isSystem) {
            return $this->redirect(Url::to('admin.roles.index') . '?err=' . rawurlencode('The built-in roles cannot be deleted.'));
        }

        $this->roles->delete($id); // assignments cascade away
        return $this->redirect(Url::to('admin.roles.index') . '?msg=deleted');
    }

    /**
     * The valid capabilities checked in the form — the fixed management set plus
     * `admin`, the content wildcards, and each collection's read/write. Anything
     * else posted is dropped.
     *
     * @return list<string>
     */
    private function capabilitiesFrom(Request $req): array
    {
        $posted = $req->all()['caps'] ?? [];
        $posted = is_array($posted) ? array_map('strval', $posted) : [];

        $valid = array_merge(['admin', '*:read', '*:write'], array_keys(self::MANAGEMENT));
        foreach ($this->collections->all() as $collection) {
            $valid[] = $collection->handle . ':read';
            $valid[] = $collection->handle . ':write';
        }

        return array_values(array_intersect($posted, $valid));
    }

    /** @return array<int,int> role id => assigned user count */
    private function assignedCounts(): array
    {
        $counts = [];
        foreach ($this->db->select('SELECT role_id, COUNT(*) AS c FROM nb_user_roles GROUP BY role_id') as $row) {
            $counts[(int) $row['role_id']] = (int) $row['c'];
        }
        return $counts;
    }
}

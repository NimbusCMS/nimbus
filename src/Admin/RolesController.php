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
        $this->requireAdmin();

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
        $this->requireAdmin();
        $this->requireCsrf($req, '/admin/roles');

        $name = trim((string) $req->input('name'));
        if ($name === '') {
            return $this->redirect('/admin/roles?err=' . rawurlencode('A role needs a name.'));
        }
        if ($this->roles->findByName($name) !== null) {
            return $this->redirect('/admin/roles?err=' . rawurlencode("A role named \"{$name}\" already exists."));
        }

        $this->roles->create($name, $this->capabilitiesFrom($req), false);
        return $this->redirect('/admin/roles?msg=created');
    }

    private function update(Request $req, int $id): Response
    {
        $this->requireAdmin();
        $this->requireCsrf($req, '/admin/roles');

        $role = $this->roles->find($id);
        if ($role === null) {
            return $this->redirect('/admin/roles?err=' . rawurlencode('No such role.'));
        }
        // The admin role is the built-in super-grant; keep it intact so an install
        // can never edit away its own administrator.
        if ($role->name === 'admin') {
            return $this->redirect('/admin/roles?err=' . rawurlencode('The admin role cannot be edited.'));
        }

        $this->roles->setCapabilities($id, $this->capabilitiesFrom($req));
        return $this->redirect('/admin/roles?msg=updated');
    }

    private function destroy(Request $req, int $id): Response
    {
        $this->requireAdmin();
        $this->requireCsrf($req, '/admin/roles');

        $role = $this->roles->find($id);
        if ($role === null) {
            return $this->redirect('/admin/roles?err=' . rawurlencode('No such role.'));
        }
        if ($role->isSystem) {
            return $this->redirect('/admin/roles?err=' . rawurlencode('The built-in roles cannot be deleted.'));
        }

        $this->roles->delete($id); // assignments cascade away
        return $this->redirect('/admin/roles?msg=deleted');
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

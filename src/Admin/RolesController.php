<?php

declare(strict_types=1);

namespace Nimbus\Admin;

use Nimbus\Auth\Auth;
use Nimbus\Auth\CapabilityRegistry;
use Nimbus\Auth\RoleRepository;
use Nimbus\Content\CollectionRepository;
use Nimbus\Database\Connection;
use Nimbus\Http\Csrf;
use Nimbus\Http\Request;
use Nimbus\Http\Response;
use Nimbus\Http\Router;
use Nimbus\Http\Url;
use Nimbus\Settings\Settings;

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

    /** ADMIN-10: post-redirect notices are fixed CODE→string maps (never URL text). */
    private const OK_NOTICES = [
        'created' => 'Role created.',
        'updated' => 'Role updated.',
        'deleted' => 'Role deleted.',
    ];
    private const ERR_NOTICES = [
        'name-required' => 'A role needs a name.',
        'name-too-long' => 'Role name must be 80 characters or fewer.',
        'name-exists'   => 'A role with that name already exists.',
        'cap-unheld'    => 'You can’t grant a capability you don’t hold.',
        'not-found'     => 'No such role.',
        'admin-locked'  => 'The admin role can’t be edited.',
        'system-locked' => 'The built-in roles can’t be deleted.',
        'role-superior' => 'You can’t modify a role that grants a capability beyond your own.',
    ];

    private RoleRepository $roles;
    private CollectionRepository $collections;
    private CapabilityRegistry $capabilities;

    public function __construct(Connection $db, Auth $auth, Settings $settings, ?AdminPageRegistry $adminPages = null, ?CapabilityRegistry $capabilities = null)
    {
        parent::__construct($db, $auth, $settings, $adminPages);
        $this->roles        = new RoleRepository($db);
        $this->collections  = new CollectionRepository($db);
        $this->capabilities = $capabilities ?? new CapabilityRegistry();
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
            // Core management capabilities plus any a plugin declared (ADR 0015),
            // rendered as the same wildcard-immune checklist.
            'management'  => array_merge(self::MANAGEMENT, $this->capabilities->grantable()),
            'editing'     => $editing,
            'counts'      => $this->assignedCounts(),
            'notice'      => $this->notice($req, self::OK_NOTICES, self::ERR_NOTICES),
            'csrf'        => Csrf::token(),
        ]);
    }

    private function store(Request $req): Response
    {
        $this->requireCan('roles', 'write');
        $this->requireCsrf($req, Url::to('admin.roles.index'));

        $name = trim((string) $req->input('name'));
        if ($name === '') {
            return $this->redirect(Url::to('admin.roles.index') . '?err=name-required');
        }
        if ($this->tooLong($name, 80)) { // nb_roles.name VARCHAR(80)
            return $this->redirect(Url::to('admin.roles.index') . '?err=name-too-long');
        }
        if ($this->roles->findByName($name) !== null) {
            return $this->redirect(Url::to('admin.roles.index') . '?err=name-exists');
        }

        $caps      = $this->capabilitiesFrom($req);
        $ungranted = $this->firstUnheld($caps);
        if ($ungranted !== null) {
            return $this->redirect(Url::to('admin.roles.index') . '?err=cap-unheld');
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
            return $this->redirect(Url::to('admin.roles.index') . '?err=not-found');
        }
        // The admin role is the built-in super-grant; keep it intact so an install
        // can never edit away its own administrator.
        if ($role->name === 'admin') {
            return $this->redirect(Url::to('admin.roles.index') . '?err=admin-locked');
        }
        // Subset-only, both ways: you cannot edit a role that already grants more
        // than you hold (no nerf-by-edit / no touching a superior role), and you
        // cannot grant a capability you do not hold.
        $existing = $this->firstUnheld($role->capabilities);
        if ($existing !== null) {
            return $this->redirect(Url::to('admin.roles.index') . '?err=role-superior');
        }
        $caps      = $this->capabilitiesFrom($req);
        $ungranted = $this->firstUnheld($caps);
        if ($ungranted !== null) {
            return $this->redirect(Url::to('admin.roles.index') . '?err=cap-unheld');
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
            return $this->redirect(Url::to('admin.roles.index') . '?err=not-found');
        }
        if ($role->isSystem) {
            return $this->redirect(Url::to('admin.roles.index') . '?err=system-locked');
        }
        // Subset-only, same as update(): you cannot DELETE a role that grants a
        // capability beyond your own — deleting it would strip a superior user's
        // access and blind role-bound tokens. Without this, a roles:write-only
        // manager could destroy any custom role regardless of what it grants.
        $superior = $this->firstUnheld($role->capabilities);
        if ($superior !== null) {
            return $this->redirect(Url::to('admin.roles.index') . '?err=role-superior');
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

        $valid = array_merge(
            ['admin', '*:read', '*:write'],
            array_keys(self::MANAGEMENT),
            array_keys($this->capabilities->grantable()), // plugin-declared management caps (ADR 0015)
        );
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

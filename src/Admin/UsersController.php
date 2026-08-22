<?php

declare(strict_types=1);

namespace Nimbus\Admin;

use Nimbus\Auth\AccountTokenService;
use Nimbus\Auth\Auth;
use Nimbus\Auth\InvitationService;
use Nimbus\Auth\Password;
use Nimbus\Auth\PasswordResetRepository;
use Nimbus\Auth\RoleRepository;
use Nimbus\Auth\UserRepository;
use Nimbus\Database\Connection;
use Nimbus\Http\Csrf;
use Nimbus\Http\Request;
use Nimbus\Http\Response;
use Nimbus\Http\Router;
use Nimbus\Mail\Mailer;
use Nimbus\Mail\MailerFactory;
use Nimbus\Support\EventDispatcher;

/**
 * The users admin page (ADR 0011) — create people and assign them roles. Users
 * were CLI/MCP-only before; roles need a place to attach. Admin-only. Deleting a
 * user is deferred (sharp, rarely needed); the last admin cannot be stripped of
 * the admin role, so an install can't lock itself out.
 */
final class UsersController extends Controller
{
    private UserRepository $users;
    private RoleRepository $roles;
    private InvitationService $invitations;

    public function __construct(Connection $db, Auth $auth, ?AdminPageRegistry $adminPages = null, ?Mailer $mailer = null, ?EventDispatcher $events = null)
    {
        parent::__construct($db, $auth, $adminPages);
        $this->users = new UserRepository($db);
        $this->roles = new RoleRepository($db);
        $this->invitations = new InvitationService(
            new AccountTokenService($this->users, new PasswordResetRepository($db), $events ?? new EventDispatcher()),
            $mailer ?? MailerFactory::fromConfig(),
        );
    }

    public function routes(Router $r): void
    {
        $r->group('/admin/users', [$this->authMw], function (Router $g): void {
            $g->get('', fn (Request $req, array $p): Response => $this->index($req))->name('admin.users.index');
            $g->post('', fn (Request $req, array $p): Response => $this->store($req));
            $g->post('/{id}', fn (Request $req, array $p): Response => $this->update($req, (int) $p['id']));
        });
    }

    private function index(Request $req): Response
    {
        $this->requireCan('users', 'write');

        $editId  = $req->query('edit');
        $editing = $editId !== null && ctype_digit($editId) ? $this->users->find((int) $editId) : null;

        $rows = [];
        foreach ($this->users->all() as $user) {
            $rows[] = ['user' => $user, 'roles' => $this->roles->rolesForUser($user->id)];
        }

        return $this->page('users/index', 'users', [
            'rows'         => $rows,
            'roles'        => $this->roles->all(),
            'editing'      => $editing,
            'editingRoles' => $editing !== null ? array_map(static fn ($r): int => $r->id, $this->roles->rolesForUser($editing->id)) : [],
            'flash'        => $req->query('msg'),
            'error'        => $req->query('err'),
            'csrf'         => Csrf::token(),
        ]);
    }

    private function store(Request $req): Response
    {
        $this->requireCan('users', 'write');
        $this->requireCsrf($req, '/admin/users');

        $email = strtolower(trim((string) $req->input('email')));
        if ($email === '' || !str_contains($email, '@')) {
            return $this->redirect('/admin/users?err=' . rawurlencode('A valid email is required.'));
        }
        if ($this->users->emailExists($email)) {
            return $this->redirect('/admin/users?err=' . rawurlencode("A user with email \"{$email}\" already exists."));
        }
        // Blank password → email an invite (the user sets their own); a typed
        // password → direct create (the fallback, e.g. a no-mail install).
        $password = (string) $req->input('password');
        $invite   = trim($password) === '';
        if (!$invite && Password::isWeak($password)) {
            return $this->redirect('/admin/users?err=' . rawurlencode('Choose a password of at least 8 non-default characters.'));
        }
        $name = trim((string) $req->input('name'));
        if ($name === '') {
            $name = ucfirst(explode('@', $email)[0]);
        }

        $roleIds     = $this->roleIdsFrom($req);
        $ungrantable = $this->firstUngrantableRole($roleIds);
        if ($ungrantable !== null) {
            return $this->redirect('/admin/users?err=' . rawurlencode("You cannot assign the \"{$ungrantable}\" role — it grants more than you hold."));
        }

        // An invited account gets a random, unusable password: no plaintext ever
        // matches, so it cannot be logged into until the invite is accepted.
        $hash = $invite ? Password::hash(bin2hex(random_bytes(32))) : Password::hash($password);

        // `users.role` keeps a legacy primary role for compatibility until
        // enforcement moves fully to capabilities; the roles below are the source
        // of authority.
        $id = $this->users->create($name, $email, $hash, 'author');
        $this->roles->syncUserRoles($id, $roleIds);

        if ($invite) {
            $sent = $this->invitations->sendInvite($id, $email);
            return $this->redirect('/admin/users?msg=' . ($sent ? 'invited' : 'invited-nomail'));
        }

        return $this->redirect('/admin/users?msg=created');
    }

    private function update(Request $req, int $id): Response
    {
        $this->requireCan('users', 'write');
        $this->requireCsrf($req, '/admin/users');

        $user = $this->users->find($id);
        if ($user === null) {
            return $this->redirect('/admin/users?err=' . rawurlencode('No such user.'));
        }

        $roleIds = $this->roleIdsFrom($req);

        // Subset-only (both directions): you cannot assign a role that grants more
        // than you hold, nor edit a user who already holds one — so a users:write
        // user can never escalate themselves or a peer to admin, and can't strip a
        // superior of a role they can't see.
        $ungrantable = $this->firstUngrantableRole($roleIds)
            ?? $this->firstUngrantableRole(array_map(static fn ($r): int => $r->id, $this->roles->rolesForUser($id)));
        if ($ungrantable !== null) {
            return $this->redirect('/admin/users?err=' . rawurlencode("You cannot grant or change the \"{$ungrantable}\" role — it grants more than you hold."));
        }

        // Never let the last admin be stripped of the admin role.
        $adminRole = $this->roles->findByName('admin');
        if ($adminRole !== null
            && !in_array($adminRole->id, $roleIds, true)
            && $this->userHasRole($id, $adminRole->id)
            && $this->roles->assignedUserCount($adminRole->id) <= 1) {
            return $this->redirect('/admin/users?err=' . rawurlencode('This is the only admin — give another user the admin role first.'));
        }

        $name = trim((string) $req->input('name'));
        if ($name !== '') {
            $this->users->setName($id, $name);
        }
        $this->roles->syncUserRoles($id, $roleIds);

        return $this->redirect('/admin/users?msg=updated');
    }

    /**
     * The name of the first role the acting user may not grant — one whose
     * capabilities include something the actor does not hold — or null. This is
     * the assignment half of subset-only: an admin holds everything, so may
     * assign any role; a lesser manager may assign only roles at or below itself.
     *
     * @param list<int> $roleIds
     */
    private function firstUngrantableRole(array $roleIds): ?string
    {
        foreach ($roleIds as $roleId) {
            $role = $this->roles->find($roleId);
            if ($role === null) {
                continue;
            }
            foreach ($role->capabilities as $capability) {
                if (!$this->gate->holds($capability)) {
                    return $role->name;
                }
            }
        }
        return null;
    }

    /** @return list<int> the role ids checked in the form */
    private function roleIdsFrom(Request $req): array
    {
        $posted = $req->all()['roles'] ?? [];
        if (!is_array($posted)) {
            return [];
        }
        $valid = array_map(static fn ($r): int => $r->id, $this->roles->all());
        return array_values(array_filter(array_map('intval', $posted), static fn (int $id): bool => in_array($id, $valid, true)));
    }

    private function userHasRole(int $userId, int $roleId): bool
    {
        foreach ($this->roles->rolesForUser($userId) as $role) {
            if ($role->id === $roleId) {
                return true;
            }
        }
        return false;
    }
}

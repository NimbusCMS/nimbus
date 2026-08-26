<?php

declare(strict_types=1);

namespace Nimbus\Admin;

use Nimbus\Api\ApiTokenRepository;
use Nimbus\Auth\Auth;
use Nimbus\Auth\Role;
use Nimbus\Auth\RoleRepository;
use Nimbus\Content\Collection;
use Nimbus\Content\CollectionRepository;
use Nimbus\Database\Connection;
use Nimbus\Http\Csrf;
use Nimbus\Http\FormNonce;
use Nimbus\Http\Request;
use Nimbus\Http\Response;
use Nimbus\Http\Router;
use Nimbus\Http\Url;
use Nimbus\Settings\Settings;
use Nimbus\Support\Config;

/**
 * Manage API tokens: mint (shown once), list them with their lifecycle state,
 * and revoke / pause / resume.
 *
 * Administrator-only: a token grants programmatic access to content, so minting
 * and revoking it is an administrative act, not an everyday editorial one.
 *
 * One departure from every other admin write: minting *renders* its result
 * rather than redirecting, because the plaintext token is shown exactly once and
 * must never travel in a URL, a session flash, or a log. Everything else — the
 * lifecycle actions — redirects like the rest of the admin.
 */
final class TokensController extends Controller
{
    /** Expiry presets offered by the form: key => a strtotime modifier (null = never). */
    private const EXPIRY = [
        'never' => null,
        '30d'   => '+30 days',
        '90d'   => '+90 days',
        '1y'    => '+1 year',
    ];

    /** ADMIN-10: post-redirect notices are fixed CODE→string maps (never URL text). */
    private const OK_NOTICES = [
        'resubmit' => 'That form was already submitted — nothing was created.',
        'revoked'  => 'Token revoked.',
        'paused'   => 'Token paused.',
        'resumed'  => 'Token resumed.',
    ];
    private const ERR_NOTICES = [
        'name-required'     => 'A token needs a name.',
        'name-too-long'     => 'Token name must be 120 characters or fewer.',
        'role-not-found'    => 'No such role.',
        'role-ungrantable'  => 'You can’t mint a token as that role — it grants access you don’t hold.',
        'scope-required'    => 'Choose “All collections”, at least one collection, or a role.',
        'scope-ungrantable' => 'You can’t grant access you don’t hold.',
        'not-found'         => 'That token no longer exists.',
        'demo-disabled'     => 'API token minting is disabled in the live demo.',
    ];

    private ApiTokenRepository $tokens;
    private CollectionRepository $collections;
    private RoleRepository $roles;

    public function __construct(Connection $db, Auth $auth, Settings $settings, ?AdminPageRegistry $adminPages = null)
    {
        parent::__construct($db, $auth, $settings, $adminPages);
        $this->tokens      = new ApiTokenRepository($this->db);
        $this->collections = new CollectionRepository($this->db);
        $this->roles       = new RoleRepository($this->db);
    }

    public function routes(Router $r): void
    {
        $r->group('/admin/tokens', [$this->authMw], function (Router $g): void {
            $g->get('', fn (Request $req, array $p): Response => $this->index($req))->name('admin.tokens.index');
            $g->post('', fn (Request $req, array $p): Response => $this->store($req));
            $g->post('/{id}/revoke', fn (Request $req, array $p): Response => $this->lifecycle($req, (int) $p['id'], 'revoke'));
            $g->post('/{id}/pause', fn (Request $req, array $p): Response => $this->lifecycle($req, (int) $p['id'], 'pause'));
            $g->post('/{id}/resume', fn (Request $req, array $p): Response => $this->lifecycle($req, (int) $p['id'], 'resume'));
        });
    }

    /** The list + create form. `$justCreated` is a freshly-minted plaintext to show once. */
    private function index(Request $req, ?string $justCreated = null): Response
    {
        $this->requireCan('tokens', 'write');

        $allRoles = $this->roles->all();
        // The dropdown offers only roles the actor can fully bind (subset-only);
        // the server re-checks on submit — the filter is convenience, not the gate.
        $grantable = array_values(array_filter($allRoles, fn (Role $r): bool => $this->firstUngrantable($r->capabilities) === null));
        $roleNames = [];
        foreach ($allRoles as $r) {
            $roleNames[$r->id] = $r->name;
        }

        return $this->page('tokens/index', 'tokens', [
            'tokens'      => $this->tokens->all(),
            'expiries'    => array_keys(self::EXPIRY),
            'collections' => $this->collections->all(),
            'roles'       => $grantable,
            'roleNames'   => $roleNames,
            'justCreated' => $justCreated,
            'notice'      => $this->notice($req, self::OK_NOTICES, self::ERR_NOTICES),
            'csrf'        => Csrf::token(),
            'nonce'       => FormNonce::issue(),
        ]);
    }

    private function store(Request $req): Response
    {
        $this->requireCan('tokens', 'write');
        $this->requireCsrf($req, Url::to('admin.tokens.index'));

        // Demo mode disables token minting: a public admin visitor could otherwise
        // mint tokens and bulk-drive the API/uploads to exhaust the shared MySQL
        // and disk (a cross-site availability risk). Enforced here + in MCP, not
        // just hidden in the UI.
        if (Config::demo()) {
            return $this->redirect(Url::to('admin.tokens.index') . '?err=demo-disabled');
        }

        $name = trim((string) $req->input('name'));
        if ($name === '') {
            return $this->redirect(Url::to('admin.tokens.index') . '?err=name-required');
        }
        if ($this->tooLong($name, 120)) { // nb_api_tokens.name VARCHAR(120)
            return $this->redirect(Url::to('admin.tokens.index') . '?err=name-too-long');
        }

        $scopes = $this->scopesFrom($req);

        // Optionally bind a role — its capabilities apply LIVE (ADR 0011).
        // Subset-only over EVERY capability the role grants, so binding a role is
        // no way around the escalation guard; the server re-resolves the id, so
        // the dropdown's filtering to grantable roles is convenience, not the
        // control (a crafted id is checked here just the same).
        $roleId  = null;
        $roleRaw = trim((string) $req->input('role'));
        if ($roleRaw !== '') {
            $role = ctype_digit($roleRaw) ? $this->roles->find((int) $roleRaw) : null;
            if ($role === null) {
                return $this->redirect(Url::to('admin.tokens.index') . '?err=role-not-found');
            }
            $ungrantableRole = $this->firstUngrantable($role->capabilities);
            if ($ungrantableRole !== null) {
                return $this->redirect(Url::to('admin.tokens.index') . '?err=role-ungrantable');
            }
            $roleId = $role->id;
        }

        // Deny-by-default: a token needs read scopes, a bound role, or both.
        if ($scopes === [] && $roleId === null) {
            return $this->redirect(Url::to('admin.tokens.index') . '?err=scope-required');
        }

        // Subset-only over the explicit scopes too (you can only grant access you
        // hold): `tokens:write` reaches this form, but a custom-role holder
        // without `*:read` must not mint a read-all token. Both the granted
        // scopes AND the role's caps are checked — neither path smuggles more
        // than the actor holds. CLI + MCP already enforce this.
        $ungrantable = $this->firstUngrantable($scopes);
        if ($ungrantable !== null) {
            return $this->redirect(Url::to('admin.tokens.index') . '?err=scope-ungrantable');
        }

        // Single-use nonce, checked only once the input is otherwise valid: a
        // reload re-POSTs a spent nonce, so it mints nothing (the mint renders
        // its secret rather than redirecting, so it cannot use Post/Redirect/Get
        // to dodge the resubmit). An invalid-input retry keeps its nonce.
        if (!FormNonce::consume($req->input('_nonce'))) {
            return $this->redirect(Url::to('admin.tokens.index') . '?msg=resubmit');
        }

        $plain = $this->tokens->create($name, $scopes, $this->expiryFrom($req->input('expires')), $roleId);

        // Rendered, never redirected: the plaintext is shown once and must not
        // land in a URL, a flash, or a log.
        return $this->index($req, $plain);
    }

    /**
     * Build the token's read scopes from the form: "All collections" grants
     * `*:read`; otherwise each checked collection grants `<handle>:read`.
     *
     * @return string[] empty when nothing was chosen (rejected by the caller)
     */
    private function scopesFrom(Request $req): array
    {
        $post = $req->all();
        if (($post['scope_all'] ?? null) !== null) {
            return ['*:read'];
        }
        $chosen = $post['scopes'] ?? [];
        if (!is_array($chosen)) {
            return [];
        }
        // Only real collection handles become scopes; a crafted value is dropped.
        $handles = array_map(static fn (Collection $c): string => $c->handle, $this->collections->all());
        $valid   = array_values(array_intersect(array_map('strval', $chosen), $handles));

        return array_map(static fn (string $handle): string => "{$handle}:read", $valid);
    }

    /**
     * The first capability the current actor does not itself hold, or null if it
     * holds them all. The subset-only guard (ADR 0011): you can only grant — into
     * a token — access you have. Shares its shape with
     * {@see RolesController::firstUnheld()} and {@see UsersController}. When the
     * role dropdown lands (Slice 4b), its role capabilities join the set checked
     * here, so binding a role is no way around the guard.
     *
     * @param string[] $capabilities
     */
    private function firstUngrantable(array $capabilities): ?string
    {
        foreach ($capabilities as $capability) {
            if (!$this->gate->holds($capability)) {
                return $capability;
            }
        }
        return null;
    }

    private function lifecycle(Request $req, int $id, string $action): Response
    {
        $this->requireCan('tokens', 'write');
        $this->requireCsrf($req, Url::to('admin.tokens.index'));

        // ADMIN-14b: report a real failure for a nonexistent id instead of a
        // false "revoked". Existence is checked first (not affected-rows), so an
        // idempotent re-revoke/pause/resume of a real token stays a success —
        // the verbs are conditional UPDATEs and rowCount 0 can't tell "missing"
        // from "already in that state".
        if (!$this->tokens->exists($id)) {
            return $this->redirect(Url::to('admin.tokens.index') . '?err=not-found');
        }
        match ($action) {
            'revoke' => $this->tokens->revoke($id),
            'pause'  => $this->tokens->pause($id),
            default  => $this->tokens->resume($id),
        };

        return $this->redirect(Url::to('admin.tokens.index') . "?msg={$action}d");
    }

    /** Turn an expiry preset into an absolute timestamp, or null for "never". */
    private function expiryFrom(?string $preset): ?string
    {
        $modifier = self::EXPIRY[$preset] ?? null;

        return $modifier === null ? null : date('Y-m-d H:i:s', (int) strtotime($modifier));
    }
}

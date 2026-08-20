<?php

declare(strict_types=1);

namespace Nimbus\Admin;

use Nimbus\Api\ApiTokenRepository;
use Nimbus\Auth\Auth;
use Nimbus\Content\Collection;
use Nimbus\Content\CollectionRepository;
use Nimbus\Database\Connection;
use Nimbus\Http\Csrf;
use Nimbus\Http\FormNonce;
use Nimbus\Http\Request;
use Nimbus\Http\Response;
use Nimbus\Http\Router;

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

    private ApiTokenRepository $tokens;
    private CollectionRepository $collections;

    public function __construct(Connection $db, Auth $auth, ?AdminPageRegistry $adminPages = null)
    {
        parent::__construct($db, $auth, $adminPages);
        $this->tokens      = new ApiTokenRepository($this->db);
        $this->collections = new CollectionRepository($this->db);
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

        return $this->page('tokens/index', 'tokens', [
            'tokens'      => $this->tokens->all(),
            'expiries'    => array_keys(self::EXPIRY),
            'collections' => $this->collections->all(),
            'justCreated' => $justCreated,
            'flash'       => $req->query('msg'),
            'error'       => $req->query('err'),
            'csrf'        => Csrf::token(),
            'nonce'       => FormNonce::issue(),
        ]);
    }

    private function store(Request $req): Response
    {
        $this->requireCan('tokens', 'write');
        $this->requireCsrf($req, '/admin/tokens');

        $name = trim((string) $req->input('name'));
        if ($name === '') {
            return $this->redirect('/admin/tokens?err=' . rawurlencode('A token needs a name.'));
        }

        // Deny-by-default: a token must be granted read on all collections or on
        // a chosen few — never nothing.
        $scopes = $this->scopesFrom($req);
        if ($scopes === []) {
            return $this->redirect('/admin/tokens?err=' . rawurlencode('Choose "All collections", or at least one collection.'));
        }

        // Subset-only escalation guard (ADR 0011): you can only grant a token
        // access you hold yourself. `tokens:write` reaches this form, but a
        // custom-role holder without `*:read` must not be able to mint a
        // read-all token — the CLI and MCP mint paths already enforce this; the
        // web form must too (its read-only construction was never an authz
        // control, only a limitation).
        $ungrantable = $this->firstUngrantable($scopes);
        if ($ungrantable !== null) {
            return $this->redirect('/admin/tokens?err=' . rawurlencode("You cannot grant access you do not hold: \"{$ungrantable}\"."));
        }

        // Single-use nonce, checked only once the input is otherwise valid: a
        // reload re-POSTs a spent nonce, so it mints nothing (the mint renders
        // its secret rather than redirecting, so it cannot use Post/Redirect/Get
        // to dodge the resubmit). An invalid-input retry keeps its nonce.
        if (!FormNonce::consume($req->input('_nonce'))) {
            return $this->redirect('/admin/tokens?msg=resubmit');
        }

        $plain = $this->tokens->create($name, $scopes, $this->expiryFrom($req->input('expires')));

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
        $this->requireCsrf($req, '/admin/tokens');

        match ($action) {
            'revoke' => $this->tokens->revoke($id),
            'pause'  => $this->tokens->pause($id),
            default  => $this->tokens->resume($id),
        };

        return $this->redirect("/admin/tokens?msg={$action}d");
    }

    /** Turn an expiry preset into an absolute timestamp, or null for "never". */
    private function expiryFrom(?string $preset): ?string
    {
        $modifier = self::EXPIRY[$preset] ?? null;

        return $modifier === null ? null : date('Y-m-d H:i:s', (int) strtotime($modifier));
    }
}

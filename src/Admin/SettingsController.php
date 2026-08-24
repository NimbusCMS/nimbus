<?php

declare(strict_types=1);

namespace Nimbus\Admin;

use Nimbus\Auth\Auth;
use Nimbus\Auth\OAuth\OAuthIdentityRepository;
use Nimbus\Auth\UserRepository;
use Nimbus\Content\CollectionRepository;
use Nimbus\Database\Connection;
use Nimbus\Http\Csrf;
use Nimbus\Http\Request;
use Nimbus\Http\Response;
use Nimbus\Http\Router;
use Nimbus\Http\Url;
use Nimbus\Settings\Settings;
use Nimbus\Settings\SettingsRegistry;
use Nimbus\Support\Config;
use Nimbus\View\AdminTheme;

/**
 * The admin Settings page. It hosts two independent things with two different
 * trust models:
 *
 * - the per-user **theme picker** (docs/design/admin-experience.md) — a
 *   *personal preference*, so no capability gate: any signed-in user picks their
 *   own skin, the write targets only their own row (no request-supplied id), and
 *   the slug is allow-listed ({@see AdminTheme::sanitize}) at write and render.
 * - the **site settings** form (`site.home`, `site.description`) — real site
 *   configuration, gated on `settings:write` (a management capability, so a
 *   content `*:write` token can never reach it). Values flow through the typed
 *   {@see SettingsRegistry} allow-list: the write loop iterates the *registry*
 *   and pulls each key from the request (never the reverse), so an unknown or
 *   hostile key has nowhere to land, and each value is registry-validated before
 *   it is stored. The description is escaped by the view at every sink.
 */
final class SettingsController extends Controller
{
    private UserRepository $users;
    private SettingsRegistry $registry;
    private CollectionRepository $collections;

    public function __construct(Connection $db, Auth $auth, Settings $settings, ?AdminPageRegistry $adminPages = null)
    {
        // $this->settings is the composed-once store (SUP-10), inherited from
        // Controller; this controller keeps only its own registry (cheap,
        // config-driven) for the field list and the validation loop.
        parent::__construct($db, $auth, $settings, $adminPages);
        $this->users       = new UserRepository($db);
        $this->collections = new CollectionRepository($db);
        $this->registry    = new SettingsRegistry($this->collections);
    }

    public function routes(Router $r): void
    {
        $r->group('/admin/settings', [$this->authMw], function (Router $g): void {
            $g->get('', fn (Request $req, array $p): Response => $this->index($req))->name('admin.settings');
            $g->post('/theme', fn (Request $req, array $p): Response => $this->saveTheme($req));
            $g->post('/site', fn (Request $req, array $p): Response => $this->saveSite($req));
        });
    }

    private function index(Request $req): Response
    {
        return $this->renderSettings($req);
    }

    /**
     * Render the settings page. On a failed save this re-renders in the POST
     * response with the operator's own values ($submitted, overlaid onto the
     * fields) and per-key validator messages ($siteErrors) — the same
     * re-render pattern EntriesController uses: no redirect, so nothing the
     * operator typed is lost, and no attacker-influenced text is ever
     * round-tripped through the URL (the ADMIN-10 class).
     *
     * @param array<string,string> $siteErrors key => validator message
     * @param array<string,string> $submitted  key => raw submitted value (overlaid onto the fields)
     */
    private function renderSettings(Request $req, array $siteErrors = [], array $submitted = []): Response
    {
        return $this->page('settings/index', 'settings', [
            'themes'    => AdminTheme::THEMES,
            'current'   => AdminTheme::sanitize($this->auth->user()?->theme),
            'flash'     => $req->query('flash'),
            'csrf'      => Csrf::token(),
            // The site-settings section renders only for holders of settings:write.
            'canEditSite'  => $this->gate->can('settings', 'write'),
            'siteFields'   => $this->siteFields($submitted),
            'siteErrors'   => $siteErrors,
            'collections'  => $this->collectionChoices(),
            // Connected accounts (ADR 0012) — a personal setting, like the theme:
            // any signed-in user manages their own links. Empty when no provider
            // is configured, so the section is hidden.
            'oauthAccounts' => $this->oauthAccounts(),
            'oauthFlash'    => $this->oauthFlash($req),
        ]);
    }

    /**
     * The configured SSO providers with this user's link status, for the
     * connected-accounts section. Config-driven (labels only, no secrets).
     *
     * @return list<array{key:string,label:string,linked:bool,email:?string}>
     */
    private function oauthAccounts(): array
    {
        $configured = array_keys(Config::oauthProviders());
        $user       = $this->auth->user();
        if ($configured === [] || $user === null) {
            return [];
        }
        $labels = ['google' => 'Google', 'github' => 'GitHub'];
        $linked = (new OAuthIdentityRepository($this->db))->forUser($user->id);

        $out = [];
        foreach ($configured as $key) {
            $out[] = [
                'key'    => $key,
                'label'  => $labels[$key] ?? ucfirst($key),
                'linked' => isset($linked[$key]),
                'email'  => $linked[$key]['email'] ?? null,
            ];
        }
        return $out;
    }

    /**
     * A one-line notice for the connected-accounts section after a link/disconnect
     * round-trip. Messages are generic (not reflected from the query) — the query
     * only selects which fixed message to show.
     *
     * @return array{kind:string,message:string}|null
     */
    private function oauthFlash(Request $req): ?array
    {
        if ($req->query('oauth') === 'linked') {
            return ['kind' => 'ok', 'message' => 'Account connected. ✦'];
        }
        if ($req->query('oauth') === 'disconnected') {
            return ['kind' => 'ok', 'message' => 'Account disconnected.'];
        }
        return match ($req->query('oauth_error')) {
            'already'  => ['kind' => 'error', 'message' => 'That account is already connected to a Nimbus user.'],
            'config'   => ['kind' => 'error', 'message' => 'That sign-in method is not available.'],
            'provider', 'unknown', 'state' => ['kind' => 'error', 'message' => 'We couldn’t complete that connection. Please try again.'],
            default    => null,
        };
    }

    /**
     * The known site settings, each with its current value, for the form.
     *
     * A key present in $submitted (a failed save) shows the operator's own
     * value rather than the stored one, so their edits survive the re-render.
     *
     * @param array<string,string> $submitted key => raw submitted value
     * @return list<array{key:string,type:string,label:string,help:string,value:string}>
     */
    private function siteFields(array $submitted = []): array
    {
        $fields = [];
        foreach ($this->registry->all() as $key => $setting) {
            $fields[] = [
                'key'   => $key,
                'type'  => $setting->type,
                'label' => $setting->label,
                'help'  => $setting->help,
                'value' => array_key_exists($key, $submitted) ? $submitted[$key] : $this->settings->get($key),
            ];
        }
        return $fields;
    }

    /** @return array<string,string> collection handle => name, for the home select */
    private function collectionChoices(): array
    {
        $choices = [];
        foreach ($this->collections->all() as $collection) {
            $choices[$collection->handle] = $collection->name;
        }
        return $choices;
    }

    /**
     * Save the site settings. Registry-driven: iterate the *known* settings and
     * read each from the request, so an unregistered key is simply never read —
     * no over-posting. Only keys actually present in the request are touched (a
     * partial save leaves the rest as-is); every submitted value is
     * registry-validated before any is stored. If any value fails, nothing is
     * written and the form re-renders with the per-field messages (SUP-4).
     * On success the write is atomic — all keys commit or none (SUP-7, via
     * {@see Settings::setMany}) — then PRG-redirects.
     */
    private function saveSite(Request $req): Response
    {
        $this->requireCsrf($req, Url::to('admin.settings'));
        $this->requireCan('settings', 'write', Url::to('admin.settings'));

        $submitted = is_array($req->all()['settings'] ?? null) ? $req->all()['settings'] : [];

        $values = [];
        $errors = [];
        $seen   = [];
        foreach ($this->registry->all() as $key => $setting) {
            // Registry-driven: only registry keys are ever read from the request,
            // so an unknown submitted key has nowhere to land. A key the request
            // omits is left unchanged.
            if (!array_key_exists($key, $submitted) || !is_string($submitted[$key])) {
                continue;
            }
            $value       = trim($submitted[$key]);
            $seen[$key]  = $value;
            $error       = $setting->validate($value);
            if ($error !== null) {
                // Collect every failure (not just the first) so the operator
                // sees all of them at once.
                $errors[$key] = $error;
                continue;
            }
            $values[$key] = $value;
        }

        if ($errors !== []) {
            // Validate-all-then-write: on any failure nothing is written, and
            // the form re-renders with the submitted values + per-field messages.
            return $this->renderSettings($req, $errors, $seen);
        }

        $this->settings->setMany($values);
        return $this->redirect(Url::to('admin.settings') . '?flash=site');
    }

    private function saveTheme(Request $req): Response
    {
        $this->requireCsrf($req, Url::to('admin.settings'));

        $user = $this->auth->user();
        if ($user !== null) {
            // Allow-list the submitted slug, then write only this user's own row.
            $this->users->setTheme($user->id, AdminTheme::sanitize($req->input('theme')));
        }
        return $this->redirect(Url::to('admin.settings') . '?flash=theme');
    }
}

<?php

declare(strict_types=1);

namespace Nimbus\Admin;

use Nimbus\Auth\Auth;
use Nimbus\Auth\LoginThrottle;
use Nimbus\Auth\OAuth\OAuthException;
use Nimbus\Auth\OAuth\OAuthIdentityRepository;
use Nimbus\Auth\OAuth\OAuthOutcome;
use Nimbus\Auth\OAuth\OAuthProviders;
use Nimbus\Auth\OAuth\OAuthService;
use Nimbus\Database\Connection;
use Nimbus\Http\Request;
use Nimbus\Http\Response;
use Nimbus\Http\Router;
use Nimbus\Http\Url;

/**
 * SSO endpoints (ADR 0012). `start` and `callback` are PUBLIC (a user signing in
 * has no session yet) — like `/admin/login`. `disconnect` is behind the auth
 * middleware. All three are throttled by IP with the same limiter as password
 * login. The provider round-trip's integrity lives in {@see OAuthService}; this
 * controller is the HTTP shell — routing, throttle, session establishment, and
 * mapping the outcome to a redirect. It never sees a client secret or a token.
 */
final class OAuthController extends Controller
{
    private OAuthService $service;

    public function __construct(
        Connection $db,
        Auth $auth,
        private OAuthProviders $providers,
        ?OAuthService $service = null,
        ?AdminPageRegistry $adminPages = null,
    ) {
        parent::__construct($db, $auth, $adminPages);
        // The service is injectable so tests drive it with a fake provider; in the
        // real runtime it is built from the configured providers.
        $this->service = $service ?? new OAuthService($providers, new OAuthIdentityRepository($db));
    }

    public function routes(Router $r): void
    {
        // Public — no auth middleware (the sign-in case has no session yet).
        $r->get('/admin/oauth/{provider}/start', fn (Request $req, array $p): Response => $this->start($req, (string) $p['provider']))->name('admin.oauth.start');
        $r->get('/admin/oauth/{provider}/callback', fn (Request $req, array $p): Response => $this->callback($req, (string) $p['provider']))->name('admin.oauth.callback');

        // Disconnect is a state change by the account owner — authed + CSRF.
        $r->group('/admin/oauth', [$this->authMw], function (Router $g): void {
            $g->post('/{provider}/disconnect', fn (Request $req, array $p): Response => $this->disconnect($req, (string) $p['provider']))->name('admin.oauth.disconnect');
        });
    }

    private function start(Request $req, string $providerKey): Response
    {
        if (!$this->providers->has($providerKey)) {
            return $this->redirect(Url::to('admin.login') . '?oauth_error=config');
        }

        $throttle = new LoginThrottle($this->db);
        $ipKey    = 'oauth-ip:' . $req->ip();
        if ($throttle->tooManyAttempts($ipKey)) {
            return $this->redirect(Url::to('admin.login') . '?oauth_error=throttled');
        }

        $intent = $req->query('intent') === OAuthService::INTENT_LINK ? OAuthService::INTENT_LINK : OAuthService::INTENT_LOGIN;
        $uid    = null;
        if ($intent === OAuthService::INTENT_LINK) {
            $user = $this->auth->user();
            if ($user === null) {
                return $this->redirect(Url::to('admin.login'));
            }
            $uid = $user->id;
        }

        try {
            $url = $this->service->start($providerKey, $intent, $uid, (string) $req->query('next', '/admin'));
        } catch (OAuthException) {
            return $this->redirect(Url::to('admin.login') . '?oauth_error=config');
        }

        return $this->redirect($url);
    }

    private function callback(Request $req, string $providerKey): Response
    {
        $throttle = new LoginThrottle($this->db);
        $ipKey    = 'oauth-ip:' . $req->ip();
        if ($throttle->tooManyAttempts($ipKey)) {
            return $this->redirect(Url::to('admin.login') . '?oauth_error=throttled');
        }

        $currentUid = $this->auth->user()?->id;
        $result     = $this->service->callback(
            $providerKey,
            (string) $req->query('code', ''),
            (string) $req->query('state', ''),
            $currentUid,
        );

        // Where errors land: a logged-in user was linking → back to settings;
        // otherwise it was a sign-in attempt → back to login.
        $back = $currentUid !== null ? Url::to('admin.settings') : Url::to('admin.login');

        switch ($result->outcome) {
            case OAuthOutcome::SignedIn:
                $throttle->clear($ipKey);
                $this->auth->login((int) $result->userId);
                return $this->redirect($result->next);

            case OAuthOutcome::Linked:
                $throttle->clear($ipKey);
                return $this->redirect(Url::to('admin.settings') . '?oauth=linked&provider=' . rawurlencode($result->providerLabel));

            case OAuthOutcome::UnknownIdentity:
                $throttle->recordFailure($ipKey);
                return $this->redirect($back . '?oauth_error=unknown');

            case OAuthOutcome::AlreadyLinked:
                $throttle->recordFailure($ipKey);
                return $this->redirect($back . '?oauth_error=already');

            case OAuthOutcome::NotConfigured:
                $throttle->recordFailure($ipKey);
                return $this->redirect($back . '?oauth_error=config');

            case OAuthOutcome::InvalidState:
            case OAuthOutcome::ProviderError:
            default:
                $throttle->recordFailure($ipKey);
                return $this->redirect($back . '?oauth_error=provider');
        }
    }

    private function disconnect(Request $req, string $providerKey): Response
    {
        $this->requireCsrf($req, Url::to('admin.settings'));
        $user = $this->auth->user();
        if ($user !== null) {
            (new OAuthIdentityRepository($this->db))->unlink($user->id, $providerKey);
        }
        return $this->redirect(Url::to('admin.settings') . '?oauth=disconnected');
    }
}

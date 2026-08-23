<?php

declare(strict_types=1);

namespace Nimbus\Admin;

use Nimbus\Auth\AccountTokenService;
use Nimbus\Auth\Auth;
use Nimbus\Auth\InvitationService;
use Nimbus\Auth\LoginThrottle;
use Nimbus\Auth\PasswordResetOutcome;
use Nimbus\Auth\PasswordResetRepository;
use Nimbus\Auth\PasswordResetService;
use Nimbus\Auth\UserRepository;
use Nimbus\Content\CollectionRepository;
use Nimbus\Database\Connection;
use Nimbus\Http\Csrf;
use Nimbus\Http\Request;
use Nimbus\Http\Response;
use Nimbus\Http\Router;
use Nimbus\Http\Url;
use Nimbus\Mail\Mailer;
use Nimbus\Settings\Settings;
use Nimbus\Settings\SettingsRegistry;
use Nimbus\Settings\SettingsRepository;
use Nimbus\Support\EventDispatcher;

/**
 * Self-service password reset — public routes (like login/logout), outside the
 * auth middleware, because a locked-out user can't be behind auth.
 *
 * Anti-enumeration: `POST /admin/forgot` ALWAYS renders the same "if that
 * account exists, a link was sent," whether or not the email is registered and
 * whether or not it was throttled. The request is rate-limited (by IP and by
 * target email) to blunt mass-mail and the residual timing signal. The reset
 * pages carry `Referrer-Policy: no-referrer` so the token can't leak via the
 * Referer header, and the token is single-use with a one-hour life.
 */
final class PasswordResetController extends Controller
{
    private PasswordResetService $service;
    private InvitationService $invitations;

    public function __construct(Connection $db, Auth $auth, Mailer $mailer, EventDispatcher $events, ?AdminPageRegistry $adminPages = null)
    {
        parent::__construct($db, $auth, $adminPages);
        $accountTokens     = new AccountTokenService(new UserRepository($db), new PasswordResetRepository($db), $events);
        $settings          = new Settings(new SettingsRepository($db), new SettingsRegistry(new CollectionRepository($db)));
        $this->service     = new PasswordResetService(new UserRepository($db), $accountTokens, $mailer, $events, $settings);
        $this->invitations = new InvitationService($accountTokens, $mailer, $settings);
    }

    public function routes(Router $r): void
    {
        $r->get('/admin/forgot', fn (Request $req, array $p): Response => $this->forgotForm())->name('admin.forgot');
        $r->post('/admin/forgot', fn (Request $req, array $p): Response => $this->sendLink($req));
        $r->get('/admin/reset', fn (Request $req, array $p): Response => $this->resetForm($req))->name('admin.reset');
        $r->post('/admin/reset', fn (Request $req, array $p): Response => $this->doReset($req));
        // Invitation acceptance — a new user setting their first password.
        $r->get('/admin/accept', fn (Request $req, array $p): Response => $this->acceptForm($req))->name('admin.accept');
        $r->post('/admin/accept', fn (Request $req, array $p): Response => $this->doAccept($req));
    }

    private function forgotForm(?string $error = null, bool $sent = false): Response
    {
        return $this->bare('forgot', ['error' => $error, 'sent' => $sent, 'csrf' => Csrf::token()]);
    }

    private function sendLink(Request $req): Response
    {
        if (!Csrf::check($req->input('_token'))) {
            return $this->forgotForm('Your session expired. Please try again.');
        }

        $email    = (string) $req->input('email');
        $throttle = new LoginThrottle($this->db);
        $ipKey    = 'pwreset-ip:' . $req->ip();
        $emailKey = 'pwreset-em:' . strtolower(trim($email));

        // Rate-limit by source AND by target; either over the cap → skip sending,
        // but still show the identical generic response (no enumeration).
        if (!$throttle->tooManyAttempts($ipKey) && !$throttle->tooManyAttempts($emailKey)) {
            $throttle->recordFailure($ipKey);
            $throttle->recordFailure($emailKey);
            $this->service->request($email, $req->ip());
        }

        return $this->forgotForm(sent: true);
    }

    private function resetForm(Request $req, ?string $error = null): Response
    {
        $token = (string) $req->query('token');
        if (!$this->service->isValid($token)) {
            return $this->bare('reset', ['valid' => false, 'token' => '', 'error' => null, 'csrf' => Csrf::token()])
                ->withHeader('Referrer-Policy', 'no-referrer');
        }

        return $this->bare('reset', ['valid' => true, 'token' => $token, 'error' => $error, 'csrf' => Csrf::token()])
            ->withHeader('Referrer-Policy', 'no-referrer');
    }

    private function doReset(Request $req): Response
    {
        if (!Csrf::check($req->input('_token'))) {
            return $this->resetForm($req, 'Your session expired. Please try again.');
        }

        $token    = (string) $req->input('token');
        $password = (string) $req->input('password');

        $outcome = $this->service->reset($token, $password, $req->ip());

        return match ($outcome) {
            PasswordResetOutcome::Ok           => $this->redirect(Url::to('admin.login') . '?reset=1'),
            PasswordResetOutcome::WeakPassword => $this->bare('reset', [
                'valid' => true,
                'token' => $token,
                'error' => 'Please choose a stronger password (at least 12 characters).',
                'csrf'  => Csrf::token(),
            ])->withHeader('Referrer-Policy', 'no-referrer'),
            PasswordResetOutcome::InvalidToken => $this->bare('reset', [
                'valid' => false, 'token' => '', 'error' => null, 'csrf' => Csrf::token(),
            ])->withHeader('Referrer-Policy', 'no-referrer'),
        };
    }

    // ------------------------------------------------------ invitation accept

    private function acceptForm(Request $req, ?string $error = null): Response
    {
        $token = (string) $req->query('token');
        $valid = $this->invitations->isValidInvite($token);

        return $this->bare('accept', [
            'valid' => $valid,
            'token' => $valid ? $token : '',
            'error' => $valid ? $error : null,
            'csrf'  => Csrf::token(),
        ])->withHeader('Referrer-Policy', 'no-referrer');
    }

    private function doAccept(Request $req): Response
    {
        if (!Csrf::check($req->input('_token'))) {
            return $this->acceptForm($req, 'Your session expired. Please try again.');
        }

        $token    = (string) $req->input('token');
        $password = (string) $req->input('password');

        $outcome = $this->invitations->accept($token, $password, $req->ip());

        return match ($outcome) {
            PasswordResetOutcome::Ok           => $this->redirect(Url::to('admin.login') . '?welcome=1'),
            PasswordResetOutcome::WeakPassword => $this->bare('accept', [
                'valid' => true,
                'token' => $token,
                'error' => 'Please choose a stronger password (at least 12 characters).',
                'csrf'  => Csrf::token(),
            ])->withHeader('Referrer-Policy', 'no-referrer'),
            PasswordResetOutcome::InvalidToken => $this->bare('accept', [
                'valid' => false, 'token' => '', 'error' => null, 'csrf' => Csrf::token(),
            ])->withHeader('Referrer-Policy', 'no-referrer'),
        };
    }
}

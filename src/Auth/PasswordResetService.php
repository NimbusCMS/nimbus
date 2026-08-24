<?php

declare(strict_types=1);

namespace Nimbus\Auth;

use Nimbus\Mail\Mailer;
use Nimbus\Mail\MailerException;
use Nimbus\Settings\Settings;
use Nimbus\Support\Config;
use Nimbus\Support\CoreEvents;
use Nimbus\Support\EventDispatcher;

/**
 * Orchestrates self-service password reset: issue a one-time emailed token, then
 * spend it to set a new password.
 *
 * Anti-enumeration is a property of the *caller* contract: {@see request()}
 * returns void and the controller always shows the same "if that account exists,
 * a link was sent" — so the *response body* never reveals whether an email is
 * registered, and the endpoint is dual-key rate-limited (IP + email).
 *
 * NOTE: unlike {@see \Nimbus\Auth\Auth::attempt} (equal-work login, AUTH-1), this
 * path does NOT yet equalize *timing* — an unknown email returns early while a
 * known one mints a token and sends mail. The rate limit blunts sampling but a
 * residual timing oracle remains (tracked as a follow-up); do not rely on this
 * being timing-safe.
 */
final class PasswordResetService
{
    public function __construct(
        private UserRepository $users,
        private AccountTokenService $tokens,
        private Mailer $mailer,
        private EventDispatcher $events,
        private Settings $settings,
    ) {
    }

    /**
     * Issue a reset link for the account with this email, if one exists. Always
     * returns void; the caller renders an identical response either way.
     */
    public function request(string $email, string $ip): void
    {
        $user = $this->users->findByEmail(trim($email));
        if ($user === null) {
            // No account: the endpoint is throttled and the response is identical,
            // so nothing here reveals whether the email is registered.
            return;
        }

        // Supersede the user's outstanding reset links, then issue exactly one.
        $token = $this->tokens->issue($user->id, PasswordResetRepository::PURPOSE_RESET, PasswordResetRepository::TTL_SECONDS);
        $link  = Config::appUrl() . '/admin/reset?token=' . urlencode($token);
        // The brand is the editable site title (falls back to the config default).
        $site  = $this->settings->title();
        $body  = 'Someone asked to reset the password for your ' . $site . " account.\n\n"
            . "Set a new password (this link is valid for one hour and can be used once):\n{$link}\n\n"
            . "If you didn't request this, you can ignore this email — your password stays the same.";

        try {
            $this->mailer->send($user->email, 'Reset your ' . $site . ' password', $body);
        } catch (MailerException $e) {
            // Never surface a delivery failure to the client (that would leak that
            // the account exists) and never log the token/link — just the reason.
            error_log('[nimbus mail] password-reset delivery failed: ' . $e->getMessage());
        }

        $this->events->emitBestEffort(CoreEvents::PASSWORD_RESET_REQUESTED, [
            'user_id' => $user->id, 'email' => $user->email, 'ip' => $ip, 'at' => date('Y-m-d H:i:s'),
        ]);
    }

    /** True if the token is currently valid (for rendering the set-password form). Does not consume. */
    public function isValid(string $token): bool
    {
        return $this->tokens->isValid($token, PasswordResetRepository::PURPOSE_RESET);
    }

    /** Spend a reset token to set a new password (the shared, hardened routine). */
    public function reset(string $token, string $newPassword, string $ip): PasswordResetOutcome
    {
        return $this->tokens->setPassword(
            $token,
            $newPassword,
            PasswordResetRepository::PURPOSE_RESET,
            CoreEvents::PASSWORD_RESET_COMPLETED,
            $ip,
        );
    }
}

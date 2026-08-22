<?php

declare(strict_types=1);

namespace Nimbus\Auth;

use Nimbus\Mail\Mailer;
use Nimbus\Mail\MailerException;
use Nimbus\Support\Config;
use Nimbus\Support\CoreEvents;
use Nimbus\Support\EventDispatcher;

/**
 * Orchestrates self-service password reset: issue a one-time emailed token, then
 * spend it to set a new password.
 *
 * Anti-enumeration is a property of the *caller* contract: {@see request()}
 * returns void and the controller always shows the same "if that account exists,
 * a link was sent" — so the response never reveals whether an email is
 * registered. A token is minted (the costly part) regardless, and the request
 * endpoint is rate-limited, so the residual timing signal is not sampleable.
 */
final class PasswordResetService
{
    public function __construct(
        private UserRepository $users,
        private PasswordResetRepository $resets,
        private Mailer $mailer,
        private EventDispatcher $events,
    ) {
    }

    /**
     * Issue a reset link for the account with this email, if one exists. Always
     * returns void; the caller renders an identical response either way.
     */
    public function request(string $email, string $ip): void
    {
        // Mint the token up front so the work is comparable whether or not the
        // account exists (the response is identical; the endpoint is throttled).
        $token = self::newToken();

        $user = $this->users->findByEmail(trim($email));
        if ($user === null) {
            return;
        }

        // Supersede any outstanding links, then issue exactly one.
        $this->resets->invalidateForUser($user->id);
        $this->resets->create($user->id, $token);

        $link = Config::appUrl() . '/admin/reset?token=' . urlencode($token);
        $body = 'Someone asked to reset the password for your ' . Config::appName() . " account.\n\n"
            . "Set a new password (this link is valid for one hour and can be used once):\n{$link}\n\n"
            . "If you didn't request this, you can ignore this email — your password stays the same.";

        try {
            $this->mailer->send($user->email, 'Reset your ' . Config::appName() . ' password', $body);
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
        return $this->resets->userIdForValidToken($token) !== null;
    }

    /**
     * Spend a token to set a new password. Strength is checked BEFORE the token
     * is consumed, so a weak attempt leaves the link usable for a retry. The
     * consume is atomic (single-use); on success every other outstanding token
     * for the user is invalidated too.
     */
    public function reset(string $token, string $newPassword, string $ip): PasswordResetOutcome
    {
        if ($this->resets->userIdForValidToken($token) === null) {
            return PasswordResetOutcome::InvalidToken;
        }
        if (Password::isWeak($newPassword)) {
            return PasswordResetOutcome::WeakPassword;
        }

        $userId = $this->resets->consume($token);
        if ($userId === null) {
            return PasswordResetOutcome::InvalidToken; // lost the single-use race
        }

        $this->users->setPassword($userId, Password::hash($newPassword));
        $this->resets->invalidateForUser($userId);

        $this->events->emitBestEffort(CoreEvents::PASSWORD_RESET_COMPLETED, [
            'user_id' => $userId, 'ip' => $ip, 'at' => date('Y-m-d H:i:s'),
        ]);

        return PasswordResetOutcome::Ok;
    }

    private static function newToken(): string
    {
        return bin2hex(random_bytes(32)); // 256 bits of CSPRNG entropy
    }
}

<?php

declare(strict_types=1);

namespace Nimbus\Auth;

use Nimbus\Mail\Mailer;
use Nimbus\Mail\MailerException;
use Nimbus\Settings\Settings;
use Nimbus\Support\Config;
use Nimbus\Support\CoreEvents;

/**
 * User invitation: email an already-created (password-less) user a one-time link
 * to set their own password and activate the account. It reuses the shared,
 * hardened token routine ({@see AccountTokenService}) under the `invite` purpose,
 * so resets and invites never interfere.
 *
 * The admin creates the user (with its roles, subject to the same subset-only
 * guard as any user creation) and calls {@see sendInvite()}. Because invitation
 * is admin-initiated (not the public, no-enumeration reset flow), a delivery
 * failure is **reported to the admin** (returned as false) rather than swallowed.
 */
final class InvitationService
{
    /** Invites last three days — async human onboarding, unlike a self-served reset. */
    public const TTL_SECONDS = 3 * 24 * 3600;

    public function __construct(
        private AccountTokenService $tokens,
        private Mailer $mailer,
        private Settings $settings,
    ) {
    }

    /** Issue an invite token for a user and email the accept link. Returns false if delivery failed (report to the admin). */
    public function sendInvite(int $userId, string $toEmail): bool
    {
        $token = $this->tokens->issue($userId, PasswordResetRepository::PURPOSE_INVITE, self::TTL_SECONDS);
        $link  = Config::appUrl() . '/admin/accept?token=' . urlencode($token);
        $site  = $this->settings->title();

        $body = 'You have been invited to ' . $site . ".\n\n"
            . "Set your password to activate your account (this link is valid for 3 days and can be used once):\n{$link}\n\n"
            . "If you weren't expecting this, you can ignore this email.";

        try {
            $this->mailer->send($toEmail, "You're invited to " . $site, $body);
        } catch (MailerException $e) {
            error_log('[nimbus mail] invitation delivery failed: ' . $e->getMessage());
            return false;
        }
        return true;
    }

    public function isValidInvite(string $token): bool
    {
        return $this->tokens->isValid($token, PasswordResetRepository::PURPOSE_INVITE);
    }

    /** Accept an invite: set the password and activate the account (shared hardened routine). */
    public function accept(string $token, string $newPassword, string $ip): PasswordResetOutcome
    {
        return $this->tokens->setPassword(
            $token,
            $newPassword,
            PasswordResetRepository::PURPOSE_INVITE,
            CoreEvents::INVITATION_ACCEPTED,
            $ip,
        );
    }
}

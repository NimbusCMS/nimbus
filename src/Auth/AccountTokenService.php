<?php

declare(strict_types=1);

namespace Nimbus\Auth;

use Nimbus\Support\EventDispatcher;

/**
 * The one hardened routine for "a one-time token that sets a user's password" —
 * shared by password reset and invitation acceptance so the security controls
 * live in a single place. Callers differ only in the token **purpose** and the
 * completion **event**.
 *
 * Strength is checked BEFORE the token is consumed, so a weak attempt leaves the
 * link usable for a retry; the consume is atomic (single-use); on success the
 * user's other outstanding tokens *of the same purpose* are invalidated.
 */
final class AccountTokenService
{
    public function __construct(
        private UserRepository $users,
        private PasswordResetRepository $tokens,
        private EventDispatcher $events,
    ) {
    }

    /** Issue a fresh token of a purpose, superseding the user's prior unused ones of that purpose. Returns the plaintext. */
    public function issue(int $userId, string $purpose, int $ttlSeconds): string
    {
        $token = bin2hex(random_bytes(32)); // 256 bits of CSPRNG entropy
        $this->tokens->invalidateForUser($userId, $purpose);
        $this->tokens->create($userId, $token, $purpose, $ttlSeconds);
        return $token;
    }

    /** True if the token is currently valid for this purpose (to render the set-password form). Does not consume. */
    public function isValid(string $token, string $purpose): bool
    {
        return $this->tokens->userIdForValidToken($token, $purpose) !== null;
    }

    /**
     * Spend a token of a purpose to set a new password, emitting $completedEvent
     * on success.
     */
    public function setPassword(string $token, string $newPassword, string $purpose, string $completedEvent, string $ip): PasswordResetOutcome
    {
        if ($this->tokens->userIdForValidToken($token, $purpose) === null) {
            return PasswordResetOutcome::InvalidToken;
        }
        if (Password::isWeak($newPassword)) {
            return PasswordResetOutcome::WeakPassword;
        }

        $userId = $this->tokens->consume($token, $purpose);
        if ($userId === null) {
            return PasswordResetOutcome::InvalidToken; // lost the single-use race
        }

        $this->users->setPassword($userId, Password::hash($newPassword));
        $this->tokens->invalidateForUser($userId, $purpose);

        $this->events->emitBestEffort($completedEvent, [
            'user_id' => $userId, 'ip' => $ip, 'at' => date('Y-m-d H:i:s'),
        ]);

        return PasswordResetOutcome::Ok;
    }
}

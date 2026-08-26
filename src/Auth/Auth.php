<?php

declare(strict_types=1);

namespace Nimbus\Auth;

use Nimbus\Database\Connection;

/**
 * Session authentication against nb_users. Verifies argon2id/bcrypt hashes and
 * transparently rehashes on a stronger algorithm when the runtime gains it.
 */
final class Auth
{
    private const SESSION_KEY = 'nimbus_uid';
    /** A fingerprint of the password hash, stamped into the session at login. A
     *  password change elsewhere makes it mismatch, so a stale session (e.g. a
     *  stolen cookie) is logged out on its next request — the invalidation
     *  `session_regenerate_id` alone can't give (it only rotates THIS session). */
    private const SESSION_STAMP = 'nimbus_pw';

    private ?User $cached = null;
    private bool $resolved = false;

    public function __construct(private Connection $db)
    {
    }

    public function attempt(string $email, string $password): bool
    {
        $row = $this->db->selectOne('SELECT * FROM nb_users WHERE email = :e', ['e' => $email]);
        // Equal work on both branches: verify against the stored hash, or a fixed
        // dummy of the same algorithm when the email is unknown — so an unknown
        // and a known email cost one identical argon2id/bcrypt verify. No fast
        // return-early branch means no timing/enumeration oracle (AUTH-1).
        $hash = $row === null ? Password::dummyHash() : (string) $row['password'];
        $ok   = Password::verify($password, $hash);
        if ($row === null || !$ok) {
            return false;
        }
        $storedHash = (string) $row['password'];
        if (Password::needsRehash($storedHash)) {
            $storedHash = Password::hash($password);
            $this->db->execute(
                'UPDATE nb_users SET password = :p, updated_at = :t WHERE id = :id',
                ['p' => $storedHash, 't' => date('Y-m-d H:i:s'), 'id' => $row['id']],
            );
        }
        session_regenerate_id(true);
        $_SESSION[self::SESSION_KEY]   = (int) $row['id'];
        $_SESSION[self::SESSION_STAMP] = self::stamp($storedHash);
        $this->resolved = false;
        return true;
    }

    /** A short, non-reversible fingerprint of a password hash (see SESSION_STAMP). */
    private static function stamp(string $passwordHash): string
    {
        return substr(hash('sha256', $passwordHash), 0, 32);
    }

    /**
     * Verify a plaintext against the CURRENT session user's stored hash — the
     * re-auth primitive for sensitive self-service actions (change-password).
     * The hash never leaves Auth. Constant-time via {@see Password::verify}.
     */
    public function verifyCurrentPassword(string $plain): bool
    {
        $id = $_SESSION[self::SESSION_KEY] ?? null;
        if ($id === null) {
            return false;
        }
        $row = $this->db->selectOne('SELECT password FROM nb_users WHERE id = :id', ['id' => $id]);
        return $row !== null && Password::verify($plain, (string) $row['password']);
    }

    /**
     * After the acting user changes THEIR OWN password: rotate the id and
     * re-stamp so THIS session stays valid, while every other session (holding
     * the old stamp) is logged out on its next request (A4).
     */
    public function refreshAfterPasswordChange(string $newHash): void
    {
        session_regenerate_id(true);
        $_SESSION[self::SESSION_STAMP] = self::stamp($newHash);
        $this->cached   = null;
        $this->resolved = false;
    }

    /**
     * Establish a session for a user WITHOUT a password — for verified external
     * logins (SSO, ADR 0012). The caller must have already proven the identity
     * (a linked provider subject); this only starts the session. Rotates the id
     * for session-fixation parity with {@see attempt()}.
     */
    public function login(int $userId): void
    {
        session_regenerate_id(true);
        $_SESSION[self::SESSION_KEY] = $userId;
        $row = $this->db->selectOne('SELECT password FROM nb_users WHERE id = :id', ['id' => $userId]);
        if ($row !== null) {
            $_SESSION[self::SESSION_STAMP] = self::stamp((string) $row['password']);
        }
        $this->cached   = null;
        $this->resolved = false;
    }

    public function logout(): void
    {
        unset($_SESSION[self::SESSION_KEY]);
        $this->cached   = null;
        $this->resolved = false;
    }

    public function check(): bool
    {
        return $this->user() !== null;
    }

    public function user(): ?User
    {
        if ($this->resolved) {
            return $this->cached;
        }
        $this->resolved = true;

        $id = $_SESSION[self::SESSION_KEY] ?? null;
        if ($id === null) {
            return $this->cached = null;
        }
        $row = $this->db->selectOne('SELECT * FROM nb_users WHERE id = :id', ['id' => $id]);
        if ($row === null) {
            return $this->cached = null;
        }
        // Password-stamp gate (A4). A session predating this feature has no stamp
        // — backfill it (protected from now on); a stamped session whose stamp no
        // longer matches the stored hash means the password changed elsewhere, so
        // fail closed and treat it as logged out.
        $current = self::stamp((string) $row['password']);
        $stamp   = $_SESSION[self::SESSION_STAMP] ?? null;
        if (!is_string($stamp)) {
            $_SESSION[self::SESSION_STAMP] = $current;
        } elseif (!hash_equals($current, $stamp)) {
            return $this->cached = null;
        }
        return $this->cached = new User(
            (int) $row['id'],
            (string) $row['name'],
            (string) $row['email'],
            (string) $row['role'],
            $row['theme'] ?? null,
            $row['avatar_url'] ?? null,
        );
    }

    public function role(): ?string
    {
        return $this->user()?->role;
    }
}

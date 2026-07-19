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

    private ?User $cached = null;
    private bool $resolved = false;

    public function __construct(private Connection $db)
    {
    }

    public function attempt(string $email, string $password): bool
    {
        $row = $this->db->selectOne('SELECT * FROM nb_users WHERE email = :e', ['e' => $email]);
        if ($row === null || !Password::verify($password, (string) $row['password'])) {
            return false;
        }
        if (Password::needsRehash((string) $row['password'])) {
            $this->db->execute(
                'UPDATE nb_users SET password = :p, updated_at = :t WHERE id = :id',
                ['p' => Password::hash($password), 't' => date('Y-m-d H:i:s'), 'id' => $row['id']]
            );
        }
        session_regenerate_id(true);
        $_SESSION[self::SESSION_KEY] = (int) $row['id'];
        $this->resolved = false;
        return true;
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

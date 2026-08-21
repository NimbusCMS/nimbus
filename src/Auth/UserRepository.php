<?php

declare(strict_types=1);

namespace Nimbus\Auth;

use Nimbus\Database\Connection;

/**
 * Reads and writes CMS users (nb_users). Small on purpose: the management tools
 * (and, in time, the CLI) create/list/re-role users through here instead of raw
 * inserts. Passwords arrive already hashed — this never sees a plaintext.
 */
final class UserRepository
{
    public function __construct(private Connection $db)
    {
    }

    /** @return list<User> */
    public function all(): array
    {
        return array_map(
            static fn (array $r): User => self::hydrate($r),
            $this->db->select('SELECT * FROM nb_users ORDER BY created_at, id'),
        );
    }

    public function find(int $id): ?User
    {
        $row = $this->db->selectOne('SELECT * FROM nb_users WHERE id = :id', ['id' => $id]);
        return $row === null ? null : self::hydrate($row);
    }

    public function findByEmail(string $email): ?User
    {
        $row = $this->db->selectOne('SELECT * FROM nb_users WHERE email = :e', ['e' => $email]);
        return $row === null ? null : self::hydrate($row);
    }

    public function emailExists(string $email): bool
    {
        return $this->db->selectOne('SELECT id FROM nb_users WHERE email = :e', ['e' => $email]) !== null;
    }

    public function countByRole(string $role): int
    {
        return (int) ($this->db->selectOne('SELECT COUNT(*) AS c FROM nb_users WHERE role = :r', ['r' => $role])['c'] ?? 0);
    }

    /** Create a user from an already-hashed password. Returns the new id. */
    public function create(string $name, string $email, string $passwordHash, string $role): int
    {
        $now = date('Y-m-d H:i:s');
        return $this->db->insert(
            'INSERT INTO nb_users (name, email, password, role, created_at, updated_at) VALUES (:n, :e, :p, :r, :c, :u)',
            ['n' => $name, 'e' => $email, 'p' => $passwordHash, 'r' => $role, 'c' => $now, 'u' => $now],
        );
    }

    public function setRole(int $id, string $role): void
    {
        $this->db->execute(
            'UPDATE nb_users SET role = :r, updated_at = :u WHERE id = :id',
            ['r' => $role, 'u' => date('Y-m-d H:i:s'), 'id' => $id],
        );
    }

    /** Set a user's admin theme preference. The caller allow-lists the slug (AdminTheme). */
    public function setTheme(int $id, string $theme): void
    {
        $this->db->execute(
            'UPDATE nb_users SET theme = :t, updated_at = :u WHERE id = :id',
            ['t' => $theme, 'u' => date('Y-m-d H:i:s'), 'id' => $id],
        );
    }

    public function setName(int $id, string $name): void
    {
        $this->db->execute(
            'UPDATE nb_users SET name = :n, updated_at = :u WHERE id = :id',
            ['n' => $name, 'u' => date('Y-m-d H:i:s'), 'id' => $id],
        );
    }

    /** @param array<string,mixed> $row */
    private static function hydrate(array $row): User
    {
        return new User(
            (int) $row['id'],
            (string) $row['name'],
            (string) $row['email'],
            (string) $row['role'],
            isset($row['theme']) ? (string) $row['theme'] : null,
            isset($row['avatar_url']) ? (string) $row['avatar_url'] : null,
        );
    }
}

<?php

declare(strict_types=1);

namespace Nimbus\Auth;

use Nimbus\Database\Connection;

/**
 * Reads and writes roles (nb_roles) and their assignment to users
 * (nb_user_roles). A user's authority is the **union** of the capabilities of
 * the roles assigned to them (ADR 0011).
 */
final class RoleRepository
{
    public function __construct(private Connection $db)
    {
    }

    /** @return list<Role> */
    public function all(): array
    {
        return array_values(array_map(
            static fn (array $r): Role => Role::fromRow($r),
            $this->db->select('SELECT * FROM nb_roles ORDER BY is_system DESC, name'),
        ));
    }

    /**
     * Has the roles system been seeded? While it hasn't (a freshly-migrated
     * install before `roles:seed`), authorization falls back to the legacy path
     * so no one is locked out (ADR 0011).
     */
    public function hasAny(): bool
    {
        return $this->db->selectOne('SELECT 1 FROM nb_roles LIMIT 1') !== null;
    }

    public function find(int $id): ?Role
    {
        $row = $this->db->selectOne('SELECT * FROM nb_roles WHERE id = :id', ['id' => $id]);
        return $row === null ? null : Role::fromRow($row);
    }

    public function findByName(string $name): ?Role
    {
        $row = $this->db->selectOne('SELECT * FROM nb_roles WHERE name = :n', ['n' => $name]);
        return $row === null ? null : Role::fromRow($row);
    }

    /**
     * Create a role. Capabilities are stored as a JSON array of strings.
     *
     * @param list<string> $capabilities
     */
    public function create(string $name, array $capabilities, bool $isSystem = false): int
    {
        $now = date('Y-m-d H:i:s');
        return $this->db->insert(
            'INSERT INTO nb_roles (name, capabilities, is_system, created_at, updated_at) VALUES (:n, :c, :s, :ca, :u)',
            ['n' => $name, 'c' => $this->encode($capabilities), 's' => $isSystem ? 1 : 0, 'ca' => $now, 'u' => $now],
        );
    }

    /** @param list<string> $capabilities */
    public function setCapabilities(int $id, array $capabilities): void
    {
        $this->db->execute(
            'UPDATE nb_roles SET capabilities = :c, updated_at = :u WHERE id = :id',
            ['c' => $this->encode($capabilities), 'u' => date('Y-m-d H:i:s'), 'id' => $id],
        );
    }

    /** Delete a role; its user assignments cascade away (FK). */
    public function delete(int $id): void
    {
        $this->db->execute('DELETE FROM nb_roles WHERE id = :id', ['id' => $id]);
    }

    /** Count the users assigned a given role. */
    public function assignedUserCount(int $roleId): int
    {
        return (int) ($this->db->selectOne('SELECT COUNT(*) AS c FROM nb_user_roles WHERE role_id = :r', ['r' => $roleId])['c'] ?? 0);
    }

    /** Assign a role to a user, idempotently (the composite key dedupes). */
    public function assignToUser(int $userId, int $roleId): void
    {
        $this->db->execute(
            'INSERT IGNORE INTO nb_user_roles (user_id, role_id) VALUES (:u, :r)',
            ['u' => $userId, 'r' => $roleId],
        );
    }

    public function unassignFromUser(int $userId, int $roleId): void
    {
        $this->db->execute('DELETE FROM nb_user_roles WHERE user_id = :u AND role_id = :r', ['u' => $userId, 'r' => $roleId]);
    }

    /**
     * Replace a user's role assignments with exactly the given set.
     *
     * @param list<int> $roleIds
     */
    public function syncUserRoles(int $userId, array $roleIds): void
    {
        $this->db->execute('DELETE FROM nb_user_roles WHERE user_id = :u', ['u' => $userId]);
        foreach (array_unique($roleIds) as $roleId) {
            if ($roleId > 0) {
                $this->assignToUser($userId, $roleId);
            }
        }
    }

    /** @return list<Role> the roles assigned to a user */
    public function rolesForUser(int $userId): array
    {
        return array_values(array_map(
            static fn (array $r): Role => Role::fromRow($r),
            $this->db->select(
                'SELECT r.* FROM nb_roles r JOIN nb_user_roles ur ON ur.role_id = r.id WHERE ur.user_id = :u ORDER BY r.name',
                ['u' => $userId],
            ),
        ));
    }

    /**
     * The union of a user's capabilities across all assigned roles.
     *
     * @return list<string>
     */
    public function capabilitiesForUser(int $userId): array
    {
        $caps = [];
        foreach ($this->rolesForUser($userId) as $role) {
            foreach ($role->capabilities as $cap) {
                $caps[$cap] = true;
            }
        }
        return array_keys($caps);
    }

    /** @param list<string> $capabilities */
    private function encode(array $capabilities): string
    {
        return json_encode(array_values(array_unique($capabilities)), JSON_THROW_ON_ERROR);
    }
}

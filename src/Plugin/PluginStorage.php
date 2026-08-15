<?php

declare(strict_types=1);

namespace Nimbus\Plugin;

use Nimbus\Database\Connection;

/**
 * A plugin's read/write access to its **own** tables (ADR 0005).
 *
 * A narrow, parameterised query surface — not the core `Connection`, not a
 * repository — so a plugin stores and queries the data it created with its own
 * migrations without holding the objects that own core's data.
 *
 * "Own tables only" is a **contract, not a sandbox**: an in-process PHP plugin
 * has the whole runtime and could open its own connection regardless, so there
 * is no enforcement to add here that a determined plugin could not bypass. What
 * this type provides is the *intended* path — parameterised statements, no core
 * connection handed over — and the boundary the docs and reviews hold plugins to.
 */
final class PluginStorage
{
    public function __construct(private Connection $db)
    {
    }

    /**
     * @param  array<string,mixed> $params
     * @return array<int,array<string,mixed>>
     */
    public function select(string $sql, array $params = []): array
    {
        return $this->db->select($sql, $params);
    }

    /**
     * @param  array<string,mixed> $params
     * @return array<string,mixed>|null
     */
    public function selectOne(string $sql, array $params = []): ?array
    {
        return $this->db->selectOne($sql, $params);
    }

    /**
     * @param  array<string,mixed> $params
     * @return int rows affected
     */
    public function execute(string $sql, array $params = []): int
    {
        return $this->db->execute($sql, $params);
    }

    /**
     * @param  array<string,mixed> $params
     * @return int the last insert id
     */
    public function insert(string $sql, array $params = []): int
    {
        return $this->db->insert($sql, $params);
    }

    /**
     * Run a unit of work atomically; a throw rolls it back.
     *
     * @template T
     * @param  callable():T $work
     * @return T
     */
    public function transaction(callable $work): mixed
    {
        return $this->db->transaction($work);
    }
}

<?php

declare(strict_types=1);

namespace Nimbus\Database;

use PDO;
use PDOException;

/**
 * The database layer: a thin, OO facade over a single lazily-opened PDO
 * connection. Every query is a prepared statement, so this is the only place
 * SQL runs.
 */
final class Connection
{
    private ?PDO $pdo = null;

    /** @param array{host:string,port?:int,name:string,user:string,pass:string} $config */
    public function __construct(private array $config)
    {
    }

    public function pdo(): PDO
    {
        if ($this->pdo === null) {
            $c   = $this->config;
            $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $c['host'], $c['port'] ?? 3306, $c['name']);
            $this->pdo = new PDO($dsn, $c['user'], $c['pass'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        }
        return $this->pdo;
    }

    /** True when the connection can be established (used by the installer). */
    public function isReady(): bool
    {
        try {
            $this->pdo();
            return true;
        } catch (PDOException) {
            return false;
        }
    }

    /**
     * @param array<string,mixed> $params
     * @return array<int,array<string,mixed>>
     */
    public function select(string $sql, array $params = []): array
    {
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll() ?: [];
    }

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>|null
     */
    public function selectOne(string $sql, array $params = []): ?array
    {
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** @param array<string,mixed> $params */
    public function execute(string $sql, array $params = []): int
    {
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    /** @param array<string,mixed> $params */
    public function insert(string $sql, array $params = []): int
    {
        $this->execute($sql, $params);
        return (int) $this->pdo()->lastInsertId();
    }

    /**
     * Run $callback inside a transaction, committing on success and rolling
     * back on any throwable. Used at the application-service level (not inside
     * individual repository methods) so a whole write is atomic.
     */
    public function transaction(callable $callback): mixed
    {
        $pdo = $this->pdo();
        if ($pdo->inTransaction()) {
            return $callback(); // already inside one — join it
        }
        $pdo->beginTransaction();
        try {
            $result = $callback();
            $pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** True when a PDOException is a duplicate-key (unique constraint) violation. */
    public static function isDuplicateKey(\PDOException $e): bool
    {
        return (int) ($e->errorInfo[1] ?? 0) === 1062;
    }

    public function tableExists(string $table): bool
    {
        $row = $this->selectOne(
            'SELECT COUNT(*) AS c FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :t',
            ['t' => $table]
        );
        return (int) ($row['c'] ?? 0) > 0;
    }
}

<?php

declare(strict_types=1);

namespace App\Database;

use PDO;
use PDOStatement;
use Psr\Log\LoggerInterface;

abstract class DatabaseDriver
{
    protected LoggerInterface $logger;
    protected ?PDO $pdo = null;
    protected array $config;

    public function __construct(LoggerInterface $logger, array $config)
    {
        $this->logger = $logger;
        $this->config = $config;
        $this->pdo = $this->connect();
    }

    abstract protected function connect(): PDO;

    // === SQL fragment methods ===

    /** Current timestamp expression: "datetime('now')", "NOW()" */
    abstract public function sqlNow(): string;

    /** Now minus N seconds for retry delays */
    abstract public function sqlNowMinusInterval(int $seconds): string;

    /** COALESCE(col, now) for initial timestamp */
    abstract public function sqlCoalesceNow(string $column): string;

    /** NULLS FIRST equivalent fragment (for ORDER BY) */
    abstract public function sqlNullsFirst(string $column): string;

    // === Statement helper methods ===

    /** INSERT OR IGNORE / ON CONFLICT DO NOTHING equivalent */
    abstract public function insertIgnore(string $table, array $data): int;

    /**
     * INSERT … ON CONFLICT DO UPDATE SET equivalent (upsert)
     *
     * @param string $table
     * @param array  $data           Column => value for insert
     * @param string $conflictColumn Conflict target column name
     * @param array  $set            Update assignments, where value can be:
     *                               null          → NULL
     *                               scalar         → literal value (param)
     *                               '=excluded'    → EXCLUDED.column / VALUES(column)
     *                               '=expr:…'      → raw SQL expression
     *                               '=now'         → current timestamp
     */
    abstract public function upsert(string $table, array $data, string $conflictColumn, array $set): void;

    // === Generic PDO methods ===

    abstract public function getDatabaseSize(): int;

    public function getPdo(): PDO
    {
        if ($this->pdo === null) {
            throw new \RuntimeException('Database connection is closed');
        }
        return $this->pdo;
    }

    public function query(string $sql, array $params = []): PDOStatement
    {
        if ($this->pdo === null) {
            throw new \RuntimeException('Database connection is closed');
        }
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);

            $this->logger->debug('SQL query executed', [
                'sql'    => $this->truncateSql($sql),
                'params' => $params,
            ]);

            return $stmt;
        } catch (\PDOException $e) {
            $this->logger->error('SQL query failed', [
                'sql'    => $this->truncateSql($sql),
                'params' => $params,
                'error'  => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function fetchAll(string $sql, array $params = []): array
    {
        return $this->query($sql, $params)->fetchAll(PDO::FETCH_ASSOC);
    }

    public function fetchOne(string $sql, array $params = []): ?array
    {
        $row = $this->query($sql, $params)->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function fetchValue(string $sql, array $params = []): mixed
    {
        $row = $this->query($sql, $params)->fetch(PDO::FETCH_NUM);
        return $row[0] ?? null;
    }

    public function execute(string $sql, array $params = []): int
    {
        return $this->query($sql, $params)->rowCount();
    }

    public function insert(string $sql, array $params = []): int
    {
        $this->query($sql, $params);
        if ($this->pdo === null) {
            throw new \RuntimeException('Database connection is closed');
        }
        return (int) $this->pdo->lastInsertId();
    }

    public function beginTransaction(): bool
    {
        if ($this->pdo === null) {
            throw new \RuntimeException('Database connection is closed');
        }
        $this->logger->debug('Transaction started');
        return $this->pdo->beginTransaction();
    }

    public function commit(): bool
    {
        if ($this->pdo === null) {
            throw new \RuntimeException('Database connection is closed');
        }
        $this->logger->debug('Transaction committed');
        return $this->pdo->commit();
    }

    public function rollback(): bool
    {
        if ($this->pdo === null) {
            throw new \RuntimeException('Database connection is closed');
        }
        $this->logger->debug('Transaction rolled back');
        return $this->pdo->rollBack();
    }

    public function transaction(callable $callback): mixed
    {
        $this->beginTransaction();
        try {
            $result = $callback();
            $this->commit();
            return $result;
        } catch (\Throwable $e) {
            $this->rollback();
            throw $e;
        }
    }

    public function close(): void
    {
        $this->pdo = null;
    }

    public function __destruct()
    {
        $this->close();
    }

    protected function truncateSql(string $sql): string
    {
        $sql = preg_replace('/\s+/', ' ', trim($sql));
        if (strlen($sql) > 500) {
            return substr($sql, 0, 500) . '...';
        }
        return $sql;
    }
}

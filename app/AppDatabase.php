<?php

namespace App;

use App\Database\DatabaseDriver;
use PDO;
use PDOStatement;
use Psr\Log\LoggerInterface;
use RuntimeException;

class AppDatabase
{
    private DatabaseDriver $driver;
    private LoggerInterface $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;

        $driverName = App::config('database.driver') ?? 'sqlite';

        $driverClass = match ($driverName) {
            'sqlite'     => 'App\\Database\\SQLiteDriver',
            'postgresql' => 'App\\Database\\PostgreSQLDriver',
            'mysql'      => 'App\\Database\\MySQLDriver',
            default      => null,
        };

        if ($driverClass === null || !class_exists($driverClass)) {
            throw new RuntimeException("Database driver not found: {$driverName}");
        }

        $this->driver = new $driverClass($logger, App::config('database'));
    }

    public function getDriver(): DatabaseDriver
    {
        return $this->driver;
    }

    public function getPdo(): PDO
    {
        return $this->driver->getPdo();
    }

    public function query(string $sql, array $params = []): PDOStatement
    {
        return $this->driver->query($sql, $params);
    }

    public function fetchAll(string $sql, array $params = []): array
    {
        return $this->driver->fetchAll($sql, $params);
    }

    public function fetchOne(string $sql, array $params = []): ?array
    {
        return $this->driver->fetchOne($sql, $params);
    }

    public function fetchValue(string $sql, array $params = []): mixed
    {
        return $this->driver->fetchValue($sql, $params);
    }

    public function execute(string $sql, array $params = []): int
    {
        return $this->driver->execute($sql, $params);
    }

    public function insert(string $sql, array $params = []): int
    {
        return $this->driver->insert($sql, $params);
    }

    public function beginTransaction(): bool
    {
        return $this->driver->beginTransaction();
    }

    public function commit(): bool
    {
        return $this->driver->commit();
    }

    public function rollback(): bool
    {
        return $this->driver->rollback();
    }

    public function transaction(callable $callback): mixed
    {
        $this->beginTransaction();
        try {
            $result = $callback($this);
            $this->commit();
            return $result;
        } catch (\Throwable $e) {
            $this->rollback();
            throw $e;
        }
    }

    // === SQL fragment delegation ===

    public function sqlNow(): string
    {
        return $this->driver->sqlNow();
    }

    public function sqlNowMinusInterval(int $seconds): string
    {
        return $this->driver->sqlNowMinusInterval($seconds);
    }

    public function sqlNowPlusInterval(int $seconds): string
    {
        return $this->driver->sqlNowPlusInterval($seconds);
    }

    public function sqlCoalesceNow(string $column): string
    {
        return $this->driver->sqlCoalesceNow($column);
    }

    public function sqlNullsFirst(string $column): string
    {
        return $this->driver->sqlNullsFirst($column);
    }

    // === Statement helper delegation ===

    public function insertIgnore(string $table, array $data): int
    {
        return $this->driver->insertIgnore($table, $data);
    }

    public function upsert(string $table, array $data, string $conflictColumn, array $set): void
    {
        $this->driver->upsert($table, $data, $conflictColumn, $set);
    }

    public function getDatabaseSize(): int
    {
        return $this->driver->getDatabaseSize();
    }

    /**
     * Run VACUUM (SQLite/PostgreSQL). Silently ignored on unsupported drivers.
     */
    public function vacuum(): void
    {
        if ($this->driver instanceof \App\Database\SQLiteDriver) {
            $this->driver->vacuum();
        } else {
            $this->logger->info('VACUUM skipped — not supported by current driver');
        }
    }

    /**
     * Check database integrity (SQLite only).
     */
    public function checkIntegrity(): bool
    {
        if ($this->driver instanceof \App\Database\SQLiteDriver) {
            return $this->driver->checkIntegrity();
        }
        $this->logger->info('Integrity check skipped — only supported on SQLite');
        return true;
    }

    public function close(): void
    {
        $this->driver->close();
    }

    private function __clone() {}

    public function __wakeup()
    {
        throw new RuntimeException("Cannot unserialize singleton");
    }

    public function __destruct()
    {
        $this->close();
    }
}

<?php

namespace App;

use PDO;
use PDOException;
use PDOStatement;
use Psr\Log\LoggerInterface;
use RuntimeException;

class AppDatabase
{
    private LoggerInterface $logger;

    private PDO $pdo;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;

        $dbPath = App::config('database.host');

        // Ensure directory exists
        $dbDir = dirname($dbPath);
        if (!is_dir($dbDir)) {
            if (!mkdir($dbDir, 0755, true)) {
                throw new RuntimeException("Cannot create database directory: {$dbDir}");
            }
        }

        try {
            $this->pdo = new PDO(
                "sqlite:{$dbPath}",
                null,
                null,
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE  => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES    => false,
                    PDO::ATTR_TIMEOUT             => 5,
                ]
            );

            // Enable WAL mode for better concurrent access
            $this->pdo->exec('PRAGMA journal_mode = WAL');
            $this->pdo->exec('PRAGMA foreign_keys = ON');
            $this->pdo->exec('PRAGMA busy_timeout = 5000');
            $this->pdo->exec('PRAGMA synchronous = NORMAL');

            // Set timezone for SQLite date functions
            $timezone = App::config('timezone') ?? 'UTC';
            date_default_timezone_set($timezone);

            $this->logger->debug('Database connection established', [
                'path' => $dbPath,
            ]);

        } catch (PDOException $e) {
            $this->logger->error('Database connection failed', [
                'path'  => $dbPath,
                'error' => $e->getMessage(),
            ]);
            throw new RuntimeException("Database connection failed: {$e->getMessage()}", 0, $e);
        }

    }

    /**
     * Get raw PDO instance
     */
    public function getPdo(): PDO
    {
        return $this->pdo;
    }

    /**
     * Execute a query and return the PDOStatement
     */
    public function query(string $sql, array $params = []): PDOStatement
    {
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);

            $this->logger->debug('SQL query executed', [
                'sql'    => $this->truncateSql($sql),
                'params' => $params,
            ]);

            return $stmt;

        } catch (PDOException $e) {
            $this->logger->error('SQL query failed', [
                'sql'    => $this->truncateSql($sql),
                'params' => $params,
                'error'  => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Fetch all rows matching query
     */
    public function fetchAll(string $sql, array $params = []): array
    {
        $stmt = $this->query($sql, $params);
        return $stmt->fetchAll();
    }

    /**
     * Fetch single row
     */
    public function fetchOne(string $sql, array $params = []): ?array
    {
        $stmt = $this->query($sql, $params);
        $result = $stmt->fetch();

        return $result !== false ? $result : null;
    }

    /**
     * Fetch single value (first column of first row)
     */
    public function fetchValue(string $sql, array $params = []): mixed
    {
        $stmt = $this->query($sql, $params);
        $result = $stmt->fetchColumn();

        return $result !== false ? $result : null;
    }

    /**
     * Execute a non-select query (INSERT, UPDATE, DELETE)
     * Returns number of affected rows
     */
    public function execute(string $sql, array $params = []): int
    {
        $stmt = $this->query($sql, $params);
        return $stmt->rowCount();
    }

    /**
     * Insert a row and return the last insert ID
     */
    public function insert(string $sql, array $params = []): int
    {
        $this->query($sql, $params);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Begin a transaction
     */
    public function beginTransaction(): bool
    {
        $this->logger->debug('Transaction started');
        return $this->pdo->beginTransaction();
    }

    /**
     * Commit a transaction
     */
    public function commit(): bool
    {
        $this->logger->debug('Transaction committed');
        return $this->pdo->commit();
    }

    /**
     * Rollback a transaction
     */
    public function rollback(): bool
    {
        $this->logger->debug('Transaction rolled back');
        return $this->pdo->rollBack();
    }

    /**
     * Execute a callback within a transaction
     *
     * @throws \Throwable
     */
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

    /**
     * Get database file size
     */
    public function getDatabaseSize(): int
    {
        $path = App::config('database.host');
        return file_exists($path) ? filesize($path) : 0;
    }

    /**
     * Vacuum the database (reclaim space)
     */
    public function vacuum(): void
    {
        $this->pdo->exec('VACUUM');
        $this->logger->info('Database vacuumed');
    }

    /**
     * Truncate SQL for logging (prevent huge logs)
     */
    private function truncateSql(string $sql): string
    {
        $sql = preg_replace('/\s+/', ' ', trim($sql));

        if (strlen($sql) > 500) {
            return substr($sql, 0, 500) . '...';
        }

        return $sql;
    }

    /**
     * Check database integrity
     */
    public function checkIntegrity(): bool
    {
        $result = $this->fetchValue('PRAGMA integrity_check');
        return $result === 'ok';
    }

    /**
     * Close the connection
     */
    public function close(): void
    {
    }

    /**
     * Prevent cloning
     */
    private function __clone() {}

    public function __wakeup()
    {
        throw new RuntimeException("Cannot unserialize singleton");
    }

    /**
     * Destructor
     */
    public function __destruct()
    {
        $this->close();
    }

}
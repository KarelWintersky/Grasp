<?php

declare(strict_types=1);

namespace App\Database;

use PDO;
use RuntimeException;

class MySQLDriver extends DatabaseDriver
{
    protected function connect(): PDO
    {
        $host = $this->config['host'] ?? '127.0.0.1';
        $port = $this->config['port'] ?? 3306;
        $dbname = $this->config['dbname'] ?? 'grasp';
        $user = $this->config['user'] ?? 'grasp';
        $password = $this->config['password'] ?? '';
        $charset = $this->config['charset'] ?? 'utf8mb4';

        $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset={$charset}";

        try {
            $pdo = new PDO($dsn, $user, $password, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE  => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES    => false,
            ]);

            $this->logger->debug('MySQL connection established', [
                'host'   => $host,
                'dbname' => $dbname,
            ]);

            return $pdo;
        } catch (\PDOException $e) {
            $this->logger->error('MySQL connection failed', [
                'host'    => $host,
                'dbname'  => $dbname,
                'error'   => $e->getMessage(),
            ]);
            throw new RuntimeException("MySQL connection failed: {$e->getMessage()}", 0, $e);
        }
    }

    // === SQL fragments ===

    public function sqlNow(): string
    {
        return 'NOW()';
    }

    public function sqlNowMinusInterval(int $seconds): string
    {
        return "NOW() - INTERVAL {$seconds} SECOND";
    }

    public function sqlCoalesceNow(string $column): string
    {
        return "COALESCE({$column}, NOW())";
    }

    public function sqlNullsFirst(string $column): string
    {
        return "{$column} IS NULL, {$column}";
    }

    // === Statement helpers ===

    public function insertIgnore(string $table, array $data): int
    {
        $cols = implode(', ', array_keys($data));
        $placeholders = implode(', ', array_fill(0, count($data), '?'));
        return $this->insert(
            "INSERT IGNORE INTO {$table} ({$cols}) VALUES ({$placeholders})",
            array_values($data)
        );
    }

    public function upsert(string $table, array $data, string $conflictColumn, array $set): void
    {
        $cols = implode(', ', array_keys($data));
        $placeholders = implode(', ', array_fill(0, count($data), '?'));

        $assignments = [];
        $params = array_values($data);

        foreach ($set as $col => $val) {
            if ($val === null) {
                $assignments[] = "{$col} = NULL";
            } elseif ($val === '=excluded') {
                $assignments[] = "{$col} = VALUES({$col})";
            } elseif ($val === '=now') {
                $assignments[] = "{$col} = {$this->sqlNow()}";
            } elseif (is_string($val) && str_starts_with($val, '=expr:')) {
                $assignments[] = "{$col} = " . substr($val, 6);
            } else {
                $assignments[] = "{$col} = ?";
                $params[] = $val;
            }
        }

        $sql = "INSERT INTO {$table} ({$cols}) VALUES ({$placeholders})"
            . " ON DUPLICATE KEY UPDATE "
            . implode(', ', $assignments);

        $this->execute($sql, $params);
    }

    // === MySQL-specific ===

    public function getDatabaseSize(): int
    {
        try {
            $dbname = $this->config['dbname'] ?? 'grasp';
            return (int) $this->fetchValue(
                'SELECT COALESCE(SUM(data_length + index_length), 0) FROM information_schema.tables WHERE table_schema = ?',
                [$dbname]
            );
        } catch (\Throwable $e) {
            $this->logger->error('Failed to get database size', ['error' => $e->getMessage()]);
            return 0;
        }
    }
}

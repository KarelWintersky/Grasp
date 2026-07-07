<?php

declare(strict_types=1);

namespace App\Database;

use PDO;
use RuntimeException;

class PostgreSQLDriver extends DatabaseDriver
{
    protected function connect(): PDO
    {
        $host = $this->config['host'] ?? '127.0.0.1';
        $port = $this->config['port'] ?? 5432;
        $dbname = $this->config['dbname'] ?? 'grasp';
        $user = $this->config['user'] ?? 'grasp';
        $password = $this->config['password'] ?? '';

        $dsn = "pgsql:host={$host};port={$port};dbname={$dbname}";

        try {
            $pdo = new PDO($dsn, $user, $password, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE  => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES    => false,
            ]);

            $this->logger->debug('PostgreSQL connection established', [
                'host'   => $host,
                'dbname' => $dbname,
            ]);

            return $pdo;
        } catch (\PDOException $e) {
            $this->logger->error('PostgreSQL connection failed', [
                'host'    => $host,
                'dbname'  => $dbname,
                'error'   => $e->getMessage(),
            ]);
            throw new RuntimeException("PostgreSQL connection failed: {$e->getMessage()}", 0, $e);
        }
    }

    // === SQL fragments ===

    public function sqlNow(): string
    {
        return 'NOW()';
    }

    public function sqlNowMinusInterval(int $seconds): string
    {
        return "NOW() - INTERVAL '{$seconds} seconds'";
    }

    public function sqlCoalesceNow(string $column): string
    {
        return "COALESCE({$column}, NOW())";
    }

    public function sqlNullsFirst(string $column): string
    {
        return "{$column} NULLS FIRST";
    }

    // === Statement helpers ===

    public function insertIgnore(string $table, array $data): int
    {
        $cols = implode(', ', array_keys($data));
        $placeholders = implode(', ', array_fill(0, count($data), '?'));
        $this->execute(
            "INSERT INTO {$table} ({$cols}) VALUES ({$placeholders}) ON CONFLICT DO NOTHING",
            array_values($data)
        );
        return 0;
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
                $assignments[] = "{$col} = EXCLUDED.{$col}";
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
            . " ON CONFLICT({$conflictColumn}) DO UPDATE SET "
            . implode(', ', $assignments);

        $this->execute($sql, $params);
    }

    // === PgSQL-specific ===

    public function getDatabaseSize(): int
    {
        try {
            return (int) $this->fetchValue('SELECT pg_database_size(current_database())');
        } catch (\Throwable $e) {
            $this->logger->error('Failed to get database size', ['error' => $e->getMessage()]);
            return 0;
        }
    }
}

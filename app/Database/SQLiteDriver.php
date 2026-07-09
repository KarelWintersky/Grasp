<?php

declare(strict_types=1);

namespace App\Database;

use PDO;
use RuntimeException;

class SQLiteDriver extends DatabaseDriver
{
    protected function connect(): PDO
    {
        $dbPath = $this->config['host'];

        $dbDir = dirname($dbPath);
        if (!is_dir($dbDir)) {
            if (!mkdir($dbDir, 0755, true)) {
                throw new RuntimeException("Cannot create database directory: {$dbDir}");
            }
        }

        try {
            $pdo = new PDO(
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

            $pdo->exec('PRAGMA journal_mode = WAL');
            $pdo->exec('PRAGMA foreign_keys = ON');
            $pdo->exec('PRAGMA busy_timeout = 5000');
            $pdo->exec('PRAGMA synchronous = NORMAL');

            $timezone = \App\App::fromConfig('timezone', 'UTC');
            date_default_timezone_set($timezone);

            $this->logger->debug('SQLite connection established', ['path' => $dbPath]);

            return $pdo;
        } catch (\PDOException $e) {
            $this->logger->error('SQLite connection failed', [
                'path'  => $dbPath,
                'error' => $e->getMessage(),
            ]);
            throw new RuntimeException("Database connection failed: {$e->getMessage()}", 0, $e);
        }
    }

    // === SQL fragments ===

    public function sqlNow(): string
    {
        return "datetime('now')";
    }

    public function sqlNowMinusInterval(int $seconds): string
    {
        return "datetime('now', '-{$seconds} seconds')";
    }

    public function sqlNowPlusInterval(int $seconds): string
    {
        return "datetime('now', '+{$seconds} seconds')";
    }

    public function sqlCoalesceNow(string $column): string
    {
        return "COALESCE({$column}, datetime('now'))";
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
        return $this->insert(
            "INSERT OR IGNORE INTO {$table} ({$cols}) VALUES ({$placeholders})",
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
                $assignments[] = "{$col} = excluded.{$col}";
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

    // === SQLite-specific ===

    public function getDatabaseSize(): int
    {
        $path = $this->config['host'];
        return file_exists($path) ? filesize($path) : 0;
    }

    public function vacuum(): void
    {
        $this->pdo?->exec('VACUUM');
        $this->logger->info('SQLite database vacuumed');
    }

    public function checkIntegrity(): bool
    {
        $result = $this->fetchValue('PRAGMA integrity_check');
        return $result === 'ok';
    }
}

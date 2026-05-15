<?php

declare(strict_types=1);

/**
 * GRASP Database Initialization Script
 *
 * Creates all tables, indexes, triggers, and views.
 * Safe to run multiple times — uses IF NOT EXISTS.
 *
 * Usage:
 *   php bin/init-database.php
 *   php bin/init-database.php --force     (drop & recreate)
 *   php bin/init-database.php --seed      (also insert sample data)
 *   php bin/init-database.php --verbose   (show SQL output)
 */

use App\Config;
use App\Database;

require_once __DIR__ . '/vendor/autoload.php';

// ============================================
// CLI Arguments
// ============================================

$options = getopt('', ['force', 'seed', 'verbose', 'help']);

if (isset($options['help'])) {
    echo <<<HELP
GRASP Database Initialization Script

Usage:
  php bin/init-database.php [options]

Options:
  --force      Drop all tables before creating (DESTRUCTIVE!)
  --seed       Insert sample data after initialization
  --verbose    Show SQL statements being executed
  --help       Show this help message

HELP;
    exit(0);
}

$isForce   = isset($options['force']);
$isSeed    = isset($options['seed']);
$isVerbose = isset($options['verbose']);

// ============================================
// Bootstrap
// ============================================

$config = Config::getInstance(__DIR__ . '/config.php');
$dbPath = $config->get('path_to_database');

echo "╔══════════════════════════════════════\n";
echo "║  GRASP Database Initialization       \n";
echo "╠══════════════════════════════════════\n";
echo "║  Database: {$dbPath}\n";
echo "║  Mode:     " . ($isForce ? "FORCE (drop & recreate)" : "SAFE (if not exists)      ") . " \n";
echo "║  Seed:     " . ($isSeed ? "YES" : "NO") . "\n";
echo "╚══════════════════════════════════════\n\n";

// ============================================
// Confirm if force mode
// ============================================

if ($isForce) {
    echo "\033[0;31m⚠ WARNING: Force mode will DROP ALL TABLES and DELETE ALL DATA!\033[0m\n";
    echo "Type 'yes' to continue: ";

    $handle = fopen('php://stdin', 'r');
    $confirmation = trim(fgets($handle));
    fclose($handle);

    if (strtolower($confirmation) !== 'yes') {
        echo "Aborted.\n";
        exit(0);
    }

    echo "\n";
}

// ============================================
// Execute SQL
// ============================================

try {
    $db = Database::getInstance();
    $pdo = $db->getPdo();

    // Enable foreign keys
    $pdo->exec('PRAGMA foreign_keys = ON');

    $statements = [];
    $errors = [];
    $executed = 0;

    // ============================================
    // Drop existing tables (force mode)
    // ============================================
    if ($isForce) {
        echo "Dropping existing tables...\n";

        $dropTables = [
            'DROP TABLE IF EXISTS repo_remotes',
            'DROP TABLE IF EXISTS update_queue',
            'DROP TABLE IF EXISTS events',
            'DROP TABLE IF EXISTS repositories',
            'DROP TABLE IF EXISTS tags',
            'DROP TABLE IF EXISTS `groups`',
            'DROP TABLE IF EXISTS system_state',
            'DROP TABLE IF EXISTS cron_registry',
        ];

        $dropViews = [
            'DROP VIEW IF EXISTS v_repositories',
            'DROP VIEW IF EXISTS v_queue',
            'DROP VIEW IF EXISTS v_events',
        ];

        $dropTriggers = [
            'DROP TRIGGER IF EXISTS trg_groups_updated_at',
            'DROP TRIGGER IF EXISTS trg_repos_updated_at',
            'DROP TRIGGER IF EXISTS trg_repos_state_change',
            'DROP TRIGGER IF EXISTS trg_repos_insert',
            'DROP TRIGGER IF EXISTS trg_repos_calc_next_update',
            'DROP TRIGGER IF EXISTS trg_system_state_change',
        ];

        foreach (array_merge($dropViews, $dropTriggers, $dropTables) as $sql) {
            executeSQL($pdo, $sql, $isVerbose);
            $executed++;
        }

        echo "  ✓ Dropped all objects\n\n";
    }

    // ============================================
    // Create Tables
    // ============================================
    echo "Creating tables...\n";

    // groups
    executeSQL($pdo, "
        CREATE TABLE IF NOT EXISTS `groups` (
            id                   INTEGER PRIMARY KEY AUTOINCREMENT,
            alias                TEXT    NOT NULL UNIQUE,
            title                TEXT    NOT NULL,
            default_update_period TEXT   NOT NULL DEFAULT '7d',
            created_at           TEXT    NOT NULL DEFAULT (datetime('now')),
            updated_at           TEXT    NOT NULL DEFAULT (datetime('now'))
        )
    ", $isVerbose); $executed++;

    // repositories
    executeSQL($pdo, "
        CREATE TABLE IF NOT EXISTS repositories (
            id                    INTEGER PRIMARY KEY AUTOINCREMENT,
            repo_state            TEXT    NOT NULL DEFAULT 'pending_clone',
            remote_url            TEXT    NOT NULL,
            user_name             TEXT    NOT NULL,
            repo_name             TEXT    NOT NULL,
            git_service           TEXT    NOT NULL,
            storage_path          TEXT,
            description           TEXT    DEFAULT '',
            comment               TEXT    DEFAULT '',
            repo_group            INTEGER,
            tags                  TEXT    DEFAULT '',
            update_interval       TEXT    NOT NULL DEFAULT '7d',
            date_insert           TEXT    NOT NULL DEFAULT (datetime('now')),
            date_update           TEXT    NOT NULL DEFAULT (datetime('now')),
            date_cloned_initial   TEXT,
            date_cloned_last      TEXT,
            calculated_next_update TEXT,

            FOREIGN KEY (repo_group) REFERENCES groups(id) ON DELETE SET NULL
        )
    ", $isVerbose); $executed++;

    // tags
    executeSQL($pdo, "
        CREATE TABLE IF NOT EXISTS tags (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            name       TEXT    NOT NULL UNIQUE,
            created_at TEXT    NOT NULL DEFAULT (datetime('now'))
        )
    ", $isVerbose); $executed++;

    // update_queue
    executeSQL($pdo, "
        CREATE TABLE IF NOT EXISTS update_queue (
            id              INTEGER PRIMARY KEY AUTOINCREMENT,
            repo_id         INTEGER NOT NULL UNIQUE,
            queue_type      TEXT    NOT NULL DEFAULT 'clone',
            priority        INTEGER NOT NULL DEFAULT 0,
            scheduled_at    TEXT,
            created_at      TEXT    NOT NULL DEFAULT (datetime('now')),
            attempts        INTEGER NOT NULL DEFAULT 0,
            last_attempt_at TEXT,

            FOREIGN KEY (repo_id) REFERENCES repositories(id) ON DELETE CASCADE
        )
    ", $isVerbose); $executed++;

    // events
    executeSQL($pdo, "
        CREATE TABLE IF NOT EXISTS events (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            datetime    TEXT    NOT NULL DEFAULT (datetime('now')),
            event_type  TEXT    NOT NULL,
            repo_id     INTEGER,
            message     TEXT    DEFAULT '',
            description TEXT    DEFAULT '',

            FOREIGN KEY (repo_id) REFERENCES repositories(id) ON DELETE SET NULL
        )
    ", $isVerbose); $executed++;

    // system_state
    executeSQL($pdo, "
        CREATE TABLE IF NOT EXISTS system_state (
            id            INTEGER PRIMARY KEY CHECK (id = 1),
            service_state TEXT    NOT NULL DEFAULT 'started',
            updated_at    TEXT    NOT NULL DEFAULT (datetime('now'))
        )
    ", $isVerbose); $executed++;

    // cron_registry
    executeSQL($pdo, "
        CREATE TABLE IF NOT EXISTS cron_registry (
            id              INTEGER PRIMARY KEY AUTOINCREMENT,
            started_at      TEXT    NOT NULL DEFAULT (datetime('now')),
            finished_at     TEXT,
            status          TEXT    NOT NULL DEFAULT 'running',
            repos_processed INTEGER DEFAULT 0,
            errors_count    INTEGER DEFAULT 0,
            log_output      TEXT    DEFAULT ''
        )
    ", $isVerbose); $executed++;

    // repo_remotes (for future mirroring support)
    executeSQL($pdo, "
        CREATE TABLE IF NOT EXISTS repo_remotes (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            repo_id     INTEGER NOT NULL,
            remote_name TEXT    NOT NULL DEFAULT 'origin',
            remote_url  TEXT    NOT NULL,
            is_mirror   INTEGER NOT NULL DEFAULT 0,

            FOREIGN KEY (repo_id) REFERENCES repositories(id) ON DELETE CASCADE
        )
    ", $isVerbose); $executed++;

    echo "  ✓ Created " . ($isForce ? '7' : '7') . " tables\n\n";

    // ============================================
    // Create Indexes
    // ============================================
    echo "Creating indexes...\n";

    $indexes = [
        // groups
        'CREATE INDEX IF NOT EXISTS idx_groups_alias ON groups(alias)',

        // repositories
        'CREATE INDEX IF NOT EXISTS idx_repos_state ON repositories(repo_state)',
        'CREATE INDEX IF NOT EXISTS idx_repos_group ON repositories(repo_group)',
        'CREATE INDEX IF NOT EXISTS idx_repos_service ON repositories(git_service)',
        'CREATE INDEX IF NOT EXISTS idx_repos_user_repo ON repositories(user_name, repo_name)',
        'CREATE INDEX IF NOT EXISTS idx_repos_next_update ON repositories(calculated_next_update)',
        'CREATE UNIQUE INDEX IF NOT EXISTS idx_repos_url_unique ON repositories(remote_url)',

        // tags
        'CREATE INDEX IF NOT EXISTS idx_tags_name ON tags(name)',

        // update_queue
        'CREATE INDEX IF NOT EXISTS idx_queue_repo_id ON update_queue(repo_id)',
        'CREATE INDEX IF NOT EXISTS idx_queue_type ON update_queue(queue_type)',
        'CREATE INDEX IF NOT EXISTS idx_queue_priority ON update_queue(priority)',
        'CREATE INDEX IF NOT EXISTS idx_queue_scheduled ON update_queue(scheduled_at)',

        // events
        'CREATE INDEX IF NOT EXISTS idx_events_datetime ON events(datetime DESC)',
        'CREATE INDEX IF NOT EXISTS idx_events_type ON events(event_type)',
        'CREATE INDEX IF NOT EXISTS idx_events_repo_id ON events(repo_id)',

        // cron_registry
        'CREATE INDEX IF NOT EXISTS idx_cron_started ON cron_registry(started_at DESC)',

        // repo_remotes
        'CREATE INDEX IF NOT EXISTS idx_remotes_repo_id ON repo_remotes(repo_id)',
    ];

    foreach ($indexes as $sql) {
        executeSQL($pdo, $sql, $isVerbose);
        $executed++;
    }

    echo "  ✓ Created indexes\n\n";

    // ============================================
    // Create Views
    // ============================================
    echo "Creating views...\n";

    // v_repositories
    executeSQL($pdo, "
        CREATE VIEW IF NOT EXISTS v_repositories AS
        SELECT 
            r.*,
            g.alias AS group_alias,
            g.title AS group_title,
            CASE 
                WHEN q.id IS NOT NULL THEN 1 
                ELSE 0 
            END AS in_queue,
            q.queue_type,
            q.priority AS queue_priority,
            q.scheduled_at AS queue_scheduled_at
        FROM repositories r
        LEFT JOIN groups g ON r.repo_group = g.id
        LEFT JOIN update_queue q ON r.id = q.repo_id
    ", $isVerbose); $executed++;

    // v_queue
    executeSQL($pdo, "
        CREATE VIEW IF NOT EXISTS v_queue AS
        SELECT 
            q.*,
            r.remote_url,
            r.user_name,
            r.repo_name,
            r.git_service,
            r.storage_path,
            r.repo_state,
            r.update_interval
        FROM update_queue q
        JOIN repositories r ON q.repo_id = r.id
        ORDER BY q.priority DESC, q.created_at ASC
    ", $isVerbose); $executed++;

    // v_events
    executeSQL($pdo, "
        CREATE VIEW IF NOT EXISTS v_events AS
        SELECT 
            e.*,
            r.user_name || '/' || r.repo_name AS repo_full_name,
            r.remote_url AS repo_url
        FROM events e
        LEFT JOIN repositories r ON e.repo_id = r.id
    ", $isVerbose); $executed++;

    echo "  ✓ Created 3 views\n\n";

    // ============================================
    // Create Triggers
    // ============================================
    echo "Creating triggers...\n";

    // groups updated_at
    executeSQL($pdo, "
        CREATE TRIGGER IF NOT EXISTS trg_groups_updated_at
            AFTER UPDATE ON groups
            FOR EACH ROW
        BEGIN
            UPDATE groups SET updated_at = datetime('now') WHERE id = OLD.id;
        END
    ", $isVerbose); $executed++;

    // repositories date_update
    executeSQL($pdo, "
        CREATE TRIGGER IF NOT EXISTS trg_repos_updated_at
            AFTER UPDATE ON repositories
            FOR EACH ROW
        BEGIN
            UPDATE repositories SET date_update = datetime('now') WHERE id = OLD.id;
        END
    ", $isVerbose); $executed++;

    // repositories state change → event
    executeSQL($pdo, "
        CREATE TRIGGER IF NOT EXISTS trg_repos_state_change
            AFTER UPDATE OF repo_state ON repositories
            FOR EACH ROW
            WHEN OLD.repo_state != NEW.repo_state
        BEGIN
            INSERT INTO events (event_type, repo_id, message)
            VALUES (
                NEW.repo_state,
                NEW.id,
                'Состояние изменено: ' || OLD.repo_state || ' -> ' || NEW.repo_state
            );
        END
    ", $isVerbose); $executed++;

    // repositories insert → event
    executeSQL($pdo, "
        CREATE TRIGGER IF NOT EXISTS trg_repos_insert
            AFTER INSERT ON repositories
            FOR EACH ROW
        BEGIN
            INSERT INTO events (event_type, repo_id, message)
            VALUES ('pending_clone', NEW.id, 'Репозиторий добавлен: ' || NEW.user_name || '/' || NEW.repo_name);
        END
    ", $isVerbose); $executed++;

    // repositories calculated_next_update
    executeSQL($pdo, "
        CREATE TRIGGER IF NOT EXISTS trg_repos_calc_next_update
            AFTER UPDATE OF date_cloned_last, update_interval ON repositories
            FOR EACH ROW
            WHEN NEW.date_cloned_last IS NOT NULL
        BEGIN
            UPDATE repositories 
            SET calculated_next_update = CASE
                WHEN NEW.update_interval = 'never' THEN NULL
                WHEN NEW.update_interval = 'manual' THEN NULL
                WHEN NEW.update_interval LIKE '%h' THEN 
                    datetime(NEW.date_cloned_last, '+' || REPLACE(NEW.update_interval, 'h', '') || ' hours')
                WHEN NEW.update_interval LIKE '%d' THEN 
                    datetime(NEW.date_cloned_last, '+' || REPLACE(NEW.update_interval, 'd', '') || ' days')
                ELSE NULL
            END
            WHERE id = NEW.id;
        END
    ", $isVerbose); $executed++;

    // system_state change → event
    executeSQL($pdo, "
        CREATE TRIGGER IF NOT EXISTS trg_system_state_change
            AFTER UPDATE OF service_state ON system_state
            FOR EACH ROW
            WHEN OLD.service_state != NEW.service_state
        BEGIN
            INSERT INTO events (event_type, message)
            VALUES (NEW.service_state, 'Состояние сервиса: ' || OLD.service_state || ' -> ' || NEW.service_state);
            
            UPDATE system_state SET updated_at = datetime('now') WHERE id = 1;
        END
    ", $isVerbose); $executed++;

    echo "  ✓ Created 6 triggers\n\n";

    // ============================================
    // Insert Default Data
    // ============================================
    echo "Inserting default data...\n";

    // Default system state
    executeSQL($pdo, "
        INSERT OR IGNORE INTO system_state (id, service_state) VALUES (1, 'started')
    ", $isVerbose); $executed++;

    echo "  ✓ System state initialized\n\n";

    // ============================================
    // Seed Data (optional)
    // ============================================
    if ($isSeed) {
        echo "Seeding sample data...\n";
        seedSampleData($pdo, $isVerbose);
        echo "\n";
    }

    // ============================================
    // Verify
    // ============================================
    echo "Verifying database...\n";

    $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
    $views = $pdo->query("SELECT name FROM sqlite_master WHERE type='view' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
    $triggers = $pdo->query("SELECT name FROM sqlite_master WHERE type='trigger' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);

    echo "  Tables:   " . count($tables) . " (" . implode(', ', $tables) . ")\n";
    echo "  Views:    " . count($views) . " (" . implode(', ', $views) . ")\n";
    echo "  Triggers: " . count($triggers) . " (" . implode(', ', $triggers) . ")\n";

    // Integrity check
    $integrity = $pdo->query('PRAGMA integrity_check')->fetchColumn();
    echo "  Integrity: " . ($integrity === 'ok' ? "\033[0;32m✓ {$integrity}\033[0m" : "\033[0;31m✗ {$integrity}\033[0m") . "\n";

    $dbSize = filesize($dbPath);
    echo "  Size:     " . formatBytes($dbSize) . "\n";

    echo "\n╔══════════════════════════════════════╗\n";
    echo "║  Database initialization complete!   ║\n";
    echo "╚══════════════════════════════════════╝\n";

} catch (\Throwable $e) {
    var_dump($e);

    echo "\n\033[0;31m╔══════════════════════════════════════╗\033[0m\n";
    echo "\033[0;31m║  DATABASE INITIALIZATION FAILED!     ║\033[0m\n";
    echo "\033[0;31m╠══════════════════════════════════════╣\033[0m\n";
    echo "\033[0;31m║  Error: {$e->getMessage()} at {$e->getLine()}\033[0m\n";
    echo "\033[0;31m╚══════════════════════════════════════╝\033[0m\n";
    exit(1);
}

// ============================================
// Helper Functions
// ============================================

/**
 * Execute a single SQL statement with error handling
 */
function executeSQL(PDO $pdo, string $sql, bool $verbose): void
{
    try {
        $pdo->exec($sql);

        if ($verbose) {
            $display = preg_replace('/\s+/', ' ', trim($sql));
            if (strlen($display) > 120) {
                $display = substr($display, 0, 117) . '...';
            }
            echo "    \033[0;90m{$display}\033[0m\n";
        }
    } catch (\PDOException $e) {
        throw new \RuntimeException(
            "SQL Error: {$e->getMessage()}\nSQL: " . preg_replace('/\s+/', ' ', trim($sql)),
            0,
            $e
        );
    }
}

/**
 * Insert sample data for development/testing
 */
function seedSampleData(PDO $pdo, bool $verbose): void
{
    // Sample groups
    $pdo->exec("INSERT OR IGNORE INTO groups (id, alias, title, default_update_period) VALUES (1, 'production',  'Production Repos',   '1h')");
    $pdo->exec("INSERT OR IGNORE INTO groups (id, alias, title, default_update_period) VALUES (2, 'development', 'Development Repos', '6h')");
    $pdo->exec("INSERT OR IGNORE INTO groups (id, alias, title, default_update_period) VALUES (3, 'archive',     'Archive',           'never')");

    if ($verbose) echo "    Groups: production, development, archive\n";

    // Sample tags
    $pdo->exec("INSERT OR IGNORE INTO tags (name) VALUES ('production')");
    $pdo->exec("INSERT OR IGNORE INTO tags (name) VALUES ('development')");
    $pdo->exec("INSERT OR IGNORE INTO tags (name) VALUES ('critical')");
    $pdo->exec("INSERT OR IGNORE INTO tags (name) VALUES ('archive')");
    $pdo->exec("INSERT OR IGNORE INTO tags (name) VALUES ('docs')");

    if ($verbose) echo "    Tags: production, development, critical, archive, docs\n";

    // Sample repositories
    $sampleRepos = [
        [
            'remote_url'   => 'https://github.com/laravel/laravel.git',
            'user_name'    => 'laravel',
            'repo_name'    => 'laravel',
            'git_service'  => 'github',
            'storage_path' => '/github/laravel/laravel.git',
            'description'  => 'Laravel is a web application framework with expressive, elegant syntax.',
            'repo_group'   => 2,
            'tags'         => 'development|docs',
            'update_interval' => '1d',
            'repo_state'   => 'frozen',
            'date_cloned_initial' => date('Y-m-d H:i:s', strtotime('-30 days')),
            'date_cloned_last'    => date('Y-m-d H:i:s', strtotime('-1 day')),
        ],
        [
            'remote_url'   => 'https://github.com/symfony/symfony.git',
            'user_name'    => 'symfony',
            'repo_name'    => 'symfony',
            'git_service'  => 'github',
            'storage_path' => '/github/symfony/symfony.git',
            'description'  => 'The Symfony PHP framework.',
            'repo_group'   => 2,
            'tags'         => 'development',
            'update_interval' => '1d',
            'repo_state'   => 'frozen',
            'date_cloned_initial' => date('Y-m-d H:i:s', strtotime('-60 days')),
            'date_cloned_last'    => date('Y-m-d H:i:s', strtotime('-2 days')),
        ],
        [
            'remote_url'   => 'https://github.com/nginx/nginx.git',
            'user_name'    => 'nginx',
            'repo_name'    => 'nginx',
            'git_service'  => 'github',
            'storage_path' => '/github/nginx/nginx.git',
            'description'  => 'Official NGINX Open Source repository.',
            'repo_group'   => 1,
            'tags'         => 'production|critical',
            'update_interval' => '6h',
            'repo_state'   => 'frozen',
            'date_cloned_initial' => date('Y-m-d H:i:s', strtotime('-90 days')),
            'date_cloned_last'    => date('Y-m-d H:i:s', strtotime('-3 hours')),
        ],
    ];

    foreach ($sampleRepos as $repo) {
        $pdo->prepare('
            INSERT OR IGNORE INTO repositories 
                (remote_url, user_name, repo_name, git_service, storage_path,
                 description, repo_group, tags, update_interval, repo_state,
                 date_cloned_initial, date_cloned_last)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ')->execute([
            $repo['remote_url'],
            $repo['user_name'],
            $repo['repo_name'],
            $repo['git_service'],
            $repo['storage_path'],
            $repo['description'],
            $repo['repo_group'],
            $repo['tags'],
            $repo['update_interval'],
            $repo['repo_state'],
            $repo['date_cloned_initial'],
            $repo['date_cloned_last'],
        ]);
    }

    if ($verbose) echo "    Repositories: 3 sample repos\n";

    echo "  ✓ Seed data inserted\n";
}

/**
 * Format bytes to human readable
 */
function formatBytes(int $bytes, int $precision = 2): string
{
    $units = ['B', 'KB', 'MB', 'GB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);

    return round($bytes, $precision) . ' ' . $units[$pow];
}
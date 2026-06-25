<?php

declare(strict_types=1);

namespace App\CronTasks;

use App\App;
use App\AppDatabase;
use Arris\AppLogger\Monolog\Logger;

/**
 * Queue Processor
 *
 * Processes the update_queue table:
 * - Fetches items due for processing
 * - Coordinates cloning and updating via RepositorySync
 * - Updates queue and repository states
 *
 * В обычном режиме берёт задачи, у которых scheduled_at <= now и last_attempt_at старше retry delay
 *
 * В --force режиме берёт самую приоритетную задачу (не более одной)
 *
 * Для каждой задачи:
 *
 * Обновляет счётчик попыток
 *
 * Меняет repo_state на cloning / updating
 *
 * Вызывает RepositorySync
 *
 * При успехе — удаляет из очереди, ставит frozen, обновляет даты клонирования
 *
 * При ошибке — ставит cloning_error / updating_error, записывает событие
 */
class QueueProcessor
{
    private AppDatabase $db;
    private Logger $logger;
    private Logger $console;
    private bool $isVerbose;
    private bool $isForce;

    /** Maximum repos to process in one run */
    private int $maxPerRun;

    /** Minimum seconds between processing attempts */
    private int $retryDelay;

    private int $processed = 0;
    private int $errors = 0;
    private array $errorLog = [];

    private bool $isDebug;
    private \App\AppConfig $config;

    /**
     * Constructor
     */
    public function __construct(
        AppDatabase $db,
        Logger $logger,
        Logger $console,
        bool    $isVerbose = false,
        bool    $isForce = false,
        bool    $isDebug = false
    ) {
        $this->db        = App::$db;
        $this->logger    = $logger;
        $this->console   = $console;
        $this->isVerbose = $isVerbose;
        $this->isForce   = $isForce;
        $this->isDebug   = $isDebug;

        $this->config = $config = App::$config;
        $this->maxPerRun  = (int) ($config->get('cron.max_per_run') ?? 3);
        $this->retryDelay = (int) ($config->get('cron.retry_delay') ?? 300);
    }

    /**
     * Process the queue
     *
     * @return array{processed: int, errors: int, error_log: array}
     */
    public function process(): array
    {
        if ($this->config->get('features.deferred_delete')) {
            $this->console->info('Processing delete queue');

            // Сначала обрабатываем удаление
            $this->processPendingDeletions();
        }

        $this->console->info('Processing update queue...');

        // Get items to process
        $items = $this->getQueueItems();

        if (empty($items)) {
            $this->console->info('  No items in queue to process.');
            return [
                'processed' => 0,
                'errors'    => 0,
                'error_log' => [],
            ];
        }

        $this->console->info(sprintf("  Found %s item(s) to process.", count($items)) );

        // Process each item
        foreach ($items as $item) {
            $this->processItem($item);

            // Respect max per run limit (unless force mode)
            if (!$this->isForce && $this->processed >= $this->maxPerRun) {
                $this->console->info("  Reached max per run limit ({$this->maxPerRun}). Remaining items will be processed next run.");
                break;
            }
        }

        return [
            'processed' => $this->processed,
            'errors'    => $this->errors,
            'error_log' => $this->errorLog,
        ];
    }

    /**
     * Get queue items due for processing
     *
     * @return \PDOStatement|array Iterator of queue items
     */
    private function getQueueItems(): iterable
    {
        if ($this->isForce) {
            // Force mode: get highest priority item regardless of schedule
            $sql = 'SELECT q.*, r.remote_url, r.user_name, r.repo_name, r.git_service, 
                           r.storage_path, r.repo_state, r.update_interval
                    FROM update_queue q
                    JOIN repositories r ON q.repo_id = r.id
                    ORDER BY q.priority DESC, q.created_at ASC
                    LIMIT 1';

            return $this->db->fetchAll($sql);
        }

        // Normal mode: get items that are due
        $sql = 'SELECT q.*, r.remote_url, r.user_name, r.repo_name, r.git_service, 
                       r.storage_path, r.repo_state, r.update_interval
                FROM update_queue q
                JOIN repositories r ON q.repo_id = r.id
                WHERE (q.scheduled_at IS NULL OR q.scheduled_at <= datetime(\'now\'))
                  AND (q.last_attempt_at IS NULL OR q.last_attempt_at <= datetime(\'now\', ?))
                ORDER BY q.priority DESC, q.created_at ASC';

        $retryDelaySeconds = "-{$this->retryDelay} seconds";

        return $this->db->fetchAll($sql, [$retryDelaySeconds]);
    }

    /**
     * Process a single queue item
     */
    private function processItem(array $item): void
    {
        $repoId    = (int) $item['repo_id'];
        $queueType = $item['queue_type'];
        $repoName  = "{$item['user_name']}/{$item['repo_name']}";

        $this->console->info("  Processing: {$repoName} [{$queueType}]");

        // Pre-git: отмечаем начало обработки (атомарно)
        $processingState = $queueType === 'clone' ? 'cloning' : 'updating';
        $this->db->transaction(function() use ($item, $repoId, $processingState, $repoName, $queueType): void {
            $this->db->execute(
                'UPDATE update_queue SET attempts = attempts + 1, last_attempt_at = datetime(\'now\') WHERE id = ?',
                [$item['id']]
            );
            $this->db->execute(
                'UPDATE repositories SET repo_state = ? WHERE id = ?',
                [$processingState, $repoId]
            );
            $this->recordEvent($processingState, $repoId,
                "Starting {$queueType}: {$repoName}");
        });

        // Git-операция (вне транзакции — может быть долгой)
        $sync = new RepositorySync($this->logger, $this->console, $this->isVerbose, $this->isDebug);

        try {
            $result = $queueType === 'clone'
                ? $sync->cloneRepository($item)
                : $sync->updateRepository($item);

            if ($result['success']) {
                // Post-git success: удаляем из очереди + обновляем даты и состояние (атомарно)
                $this->db->transaction(function() use ($item, $repoId, $queueType, $repoName): void {
                    $this->db->execute('DELETE FROM update_queue WHERE id = ?', [$item['id']]);

                    if ($queueType === 'clone') {
                        $this->db->execute(
                            'UPDATE repositories SET 
                            date_cloned_initial = COALESCE(date_cloned_initial, datetime(\'now\')),
                            date_cloned_last = datetime(\'now\'),
                            repo_state = ? WHERE id = ?',
                            ['pending_update', $repoId]
                        );
                    } else {
                        $this->db->execute(
                            'UPDATE repositories SET date_cloned_last = datetime(\'now\'), repo_state = ? WHERE id = ?',
                            ['pending_update', $repoId]
                        );
                    }

                    $this->recordEvent('pending_update', $repoId,
                        "Successfully completed {$queueType}: {$repoName}");
                });

                $this->console->info("    ✓ Completed successfully");
                $this->processed++;

            } else {
                // Failure
                $errorState = $queueType === 'clone' ? 'cloning_error' : 'updating_error';

                $this->db->transaction(function() use ($repoId, $errorState, $queueType, $repoName, $result): void {
                    $this->db->execute(
                        'UPDATE repositories SET repo_state = ? WHERE id = ?',
                        [$errorState, $repoId]
                    );
                    $this->recordEvent($errorState, $repoId,
                        "Failed {$queueType}: {$repoName}",
                        $result['error'] ?? 'Unknown error');
                });

                $this->console->error("    ✗ Failed: " . ($result['error'] ?? 'Unknown error'));
                $this->errors++;
                $this->errorLog[] = "{$repoName}: {$result['error']}";
            }

        } catch (\Throwable $e) {
            $errorState = $queueType === 'clone' ? 'cloning_error' : 'updating_error';

            $this->db->transaction(function() use ($repoId, $errorState, $queueType, $repoName, $e): void {
                $this->db->execute(
                    'UPDATE repositories SET repo_state = ? WHERE id = ?',
                    [$errorState, $repoId]
                );
                $this->recordEvent($errorState, $repoId,
                    "Exception during {$queueType}: {$repoName}",
                    $e->getMessage());
            });

            $this->console->error("    ✗ Exception: {$e->getMessage()}");
            $this->errors++;
            $this->errorLog[] = "{$repoName}: {$e->getMessage()}";

            $this->logger->error("Exception processing {$repoName}", [
                'exception' => $e->getMessage(),
                'file'      => $e->getFile(),
                'line'      => $e->getLine(),
            ]);
        }
    }

    /**
     * Record an event
     */
    private function recordEvent(string $type, int $repoId, string $message, string $description = ''): void
    {
        try {
            $this->db->insert(
                'INSERT INTO events (event_type, repo_id, message, description) VALUES (?, ?, ?, ?)',
                [$type, $repoId, $message, $description]
            );
        } catch (\Throwable $e) {
            $this->logger->error('Failed to record event', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Process repositories marked for deletion
     */
    private function processPendingDeletions(): void
    {
        $toDelete = $this->db->fetchAll(
            "SELECT id, user_name, repo_name, storage_path FROM repositories WHERE repo_state = 'pending_delete'"
        );

        if (empty($toDelete)) {
            return;
        }

        $this->console->info("  Found " . count($toDelete) . " repo(s) to delete.");

        $storagePath = $this->config->get('storage.path', '/opt/grasp/storage');

        foreach ($toDelete as $repo) {
            $repoId = (int) $repo['id'];
            $repoName = "{$repo['user_name']}/{$repo['repo_name']}";
            $fullPath = rtrim($storagePath, '/') . '/' . ltrim($repo['storage_path'] ?? '', '/');

            $this->console->info("    Deleting: {$repoName}");
            $this->console->info("      Path: {$fullPath}");

            // Удаляем файлы (вне транзакции — I/O)
            $filesDeleted = $this->deleteDirectory($fullPath);

            // DB-операции (атомарно)
            $this->db->transaction(function() use ($repoId, $repoName, $filesDeleted): void {
                if ($filesDeleted) {
                    $this->db->execute('DELETE FROM repositories WHERE id = ?', [$repoId]);
                    $this->recordEvent('deleted', null, "Repository deleted: {$repoName}");
                } else {
                    $this->db->execute(
                        'UPDATE repositories SET repo_state = ? WHERE id = ?',
                        ['storage_error', $repoId]
                    );
                    $this->recordEvent('storage_error', $repoId,
                        "Failed to delete repository files");
                }
            });

            if ($filesDeleted) {
                $this->console->info("      ✓ Deleted successfully");
                $this->processed++;
            } else {
                $this->console->error("      ✗ Failed to delete files");
                $this->errors++;
                $this->errorLog[] = "{$repoName}: Failed to delete files at {$fullPath}";
            }
        }
    }

    /**
     * Recursively delete a directory
     */
    private function deleteDirectory(string $path): bool
    {
        if (!is_dir($path)) {
            // Directory doesn't exist — consider it "deleted"
            return true;
        }

        try {
            $items = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );

            foreach ($items as $item) {
                $itemPath = $item->getRealPath();

                if ($item->isDir()) {
                    rmdir($itemPath);
                } else {
                    unlink($itemPath);
                }
            }

            rmdir($path);

            // Удаляем родительские директории если пустые (user, service)
            $this->cleanupEmptyParents(dirname($path));

            return true;
        } catch (\Throwable $e) {
            $this->logger->error('Failed to delete directory', [
                'path'  => $path,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Clean up empty parent directories (user folder, service folder)
     */
    private function cleanupEmptyParents(string $path): void
    {
        $storagePath = $this->config->get('storage.path', '/opt/grasp/storage');
        $storagePath = rtrim($storagePath, '/');

        // Don't go above storage root
        while ($path !== $storagePath && $path !== '/' && $path !== '') {
            if (!is_dir($path)) {
                break;
            }

            // Check if directory is empty
            $files = scandir($path);
            $isEmpty = count(array_diff($files, ['.', '..'])) === 0;

            if ($isEmpty) {
                rmdir($path);
                $this->console->info("      Cleaned up empty dir: {$path}");
            } else {
                break; // Directory not empty — stop
            }

            $path = dirname($path);
        }
    }
}
<?php

declare(strict_types=1);

namespace App\CronTasks;

use App\Database;
use App\Logger;
use App\Config;

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
    private Database $db;
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

    /**
     * Constructor
     */
    public function __construct(
        Database $db,
        Logger $logger,
        Logger $console,
        bool $isVerbose = false,
        bool $isForce = false
    ) {
        $this->db        = $db;
        $this->logger    = $logger;
        $this->console   = $console;
        $this->isVerbose = $isVerbose;
        $this->isForce   = $isForce;

        $config = Config::getInstance();
        $this->maxPerRun  = (int) ($config->get('cron_max_per_run') ?? 3);
        $this->retryDelay = (int) ($config->get('cron_retry_delay') ?? 300);
    }

    /**
     * Process the queue
     *
     * @return array{processed: int, errors: int, error_log: array}
     */
    public function process(): array
    {
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

        $this->console->info("  Found {$items->count()} item(s) to process.");

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

        // Update attempt counter
        $this->db->execute(
            'UPDATE update_queue SET attempts = attempts + 1, last_attempt_at = datetime(\'now\') WHERE id = ?',
            [$item['id']]
        );

        // Update repo state to processing
        $processingState = $queueType === 'clone' ? 'cloning' : 'updating';
        $this->db->execute(
            'UPDATE repositories SET repo_state = ? WHERE id = ?',
            [$processingState, $repoId]
        );

        // Record event
        $this->recordEvent($processingState, $repoId,
            "Starting {$queueType}: {$repoName}");

        // Process
        $sync = new RepositorySync($this->db, $this->logger, $this->console, $this->isVerbose);

        try {
            if ($queueType === 'clone') {
                $result = $sync->cloneRepository($item);
            } else {
                $result = $sync->updateRepository($item);
            }

            if ($result['success']) {
                // Success - remove from queue
                $this->db->execute('DELETE FROM update_queue WHERE id = ?', [$item['id']]);

                // Update repo state
                $this->db->execute(
                    'UPDATE repositories SET 
                        repo_state = ?,
                        date_cloned_last = datetime(\'now\')
                     WHERE id = ?',
                    ['frozen', $repoId]
                );

                // Update initial clone date if this was first clone
                if ($queueType === 'clone') {
                    $this->db->execute(
                        'UPDATE repositories SET date_cloned_initial = datetime(\'now\') WHERE id = ? AND date_cloned_initial IS NULL',
                        [$repoId]
                    );
                }

                $this->recordEvent('frozen', $repoId,
                    "Successfully completed {$queueType}: {$repoName}");

                $this->console->info("    ✓ Completed successfully");
                $this->processed++;

            } else {
                // Failure
                $errorState = $queueType === 'clone' ? 'cloning_error' : 'updating_error';

                $this->db->execute(
                    'UPDATE repositories SET repo_state = ? WHERE id = ?',
                    [$errorState, $repoId]
                );

                $this->recordEvent($errorState, $repoId,
                    "Failed {$queueType}: {$repoName}",
                    $result['error'] ?? 'Unknown error');

                $this->console->error("    ✗ Failed: " . ($result['error'] ?? 'Unknown error'));
                $this->errors++;
                $this->errorLog[] = "{$repoName}: {$result['error']}";
            }

        } catch (\Throwable $e) {
            $errorState = $queueType === 'clone' ? 'cloning_error' : 'updating_error';

            $this->db->execute(
                'UPDATE repositories SET repo_state = ? WHERE id = ?',
                [$errorState, $repoId]
            );

            $this->recordEvent($errorState, $repoId,
                "Exception during {$queueType}: {$repoName}",
                $e->getMessage());

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
}
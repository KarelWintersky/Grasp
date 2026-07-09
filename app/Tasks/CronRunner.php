<?php

declare(strict_types=1);

namespace App\Tasks;

use App\App;
use Arris\AppLogger\Monolog\Logger;

/**
 * Cron Runner - Main Orchestrator
 *
 * Coordinates the cron execution:
 * 1. Checks if another instance is running (lock file)
 * 2. Records cron run in registry
 * 3. Processes the update queue
 * 4. Updates system state
 * 5. Cleans up
 *
 * Lock-файл — предотвращает параллельный запуск через flock(). Если lock старше таймаута — считается устаревшим и удаляется
 *
 * Проверка состояния сервиса — если сервис stopped или frozen, крон не работает
 *
 * Запись в cron_registry — логирует каждый запуск (начало, конец, результаты)
 *
 * Запуск QueueProcessor
 */
class CronRunner
{
    private Logger $logger;
    private Logger $console;

    private bool $isVerbose;
    private bool $isForce;
    private bool $isDebug;

    private string $lockFile;
    private int $lockTimeout;
    private bool $lockCheckPid;

    /** @var resource|null */
    private $lockHandle = null;

    private int $reposProcessed = 0;
    private int $errorsCount = 0;
    private array $errorLog = [];
    private \App\AppDatabase $db;


    /**
     * Constructor
     */
    public function __construct(Logger $logger, Logger $console, bool $isVerbose = false, bool $isForce = false, bool $isDebug = false)
    {
        $this->db = App::db();

        $this->logger    = $logger;
        $this->console   = $console;
        $this->isVerbose = $isVerbose;
        $this->isForce   = $isForce;
        $this->isDebug   = $isDebug;

        $this->lockFile     = App::fromConfig('cron.lock_file', default: '/tmp/grasp_cron.lock');
        $this->lockTimeout  = (int) App::fromConfig('cron.lock_timeout', default: 300);
        $this->lockCheckPid = (bool)App::fromConfig('cron.lock_check_pid', default: true);
    }

    /**
     * Main execution method
     *
     * @return array{processed: int, errors: int, status: string}
     */
    public function run(): array
    {
        // 1. Acquire lock
        if (!$this->acquireLock()) {
            $this->console->warning('Another cron instance is already running. Exiting.');
            return [
                'processed' => 0,
                'errors'    => 0,
                'status'    => 'locked',
            ];
        }

        // 2. Check if service is running
        if (!$this->isServiceRunning()) {
            $this->console->warning('Service is stopped or frozen. Skipping processing.');
            $this->releaseLock();
            return [
                'processed' => 0,
                'errors'    => 0,
                'status'    => 'service_stopped',
            ];
        }

        // 3. Record cron start
        $cronId = $this->recordCronStart();

        // 4. Process queue
        try {
            $this->processQueue();
        } catch (\Throwable $e) {
            $this->logger->error('Error processing queue', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            $this->errorLog[] = $e->getMessage();
            $this->errorsCount++;
        }

        // 5. Record cron finish
        $this->recordCronFinish($cronId);

        // 6. Release lock
        $this->releaseLock();

        // 7. Log summary
        $this->logger->info('Cron run completed', [
            'processed' => $this->reposProcessed,
            'errors'    => $this->errorsCount,
        ]);

        return [
            'processed' => $this->reposProcessed,
            'errors'    => $this->errorsCount,
            'status'    => $this->errorsCount > 0 ? 'completed_with_errors' : 'completed',
        ];
    }

    /**
     * Acquire exclusive lock to prevent concurrent runs
     */
    private function acquireLock(): bool
    {
        // Check if lock file exists and is stale
        if (file_exists($this->lockFile)) {
            $lockAge = time() - filemtime($this->lockFile);

            if ($lockAge < $this->lockTimeout) {
                // Lock is fresh - another instance is running
                return false;
            }

            // Lock is older than timeout — optionally check if the owning process is still alive
            if ($this->lockCheckPid && $this->isLockOwnerAlive()) {
                $this->console->warning("Lock file is old but process (PID from lock) is still running. Respecting lock.");
                return false;
            }

            // Lock is stale - remove it
            $this->console->warning("Removing stale lock file (age: {$lockAge}s)");
            @unlink($this->lockFile);
        }

        // Create lock file
        $this->lockHandle = @fopen($this->lockFile, 'w');

        if ($this->lockHandle === false) {
            $this->logger->error('Cannot create lock file', ['path' => $this->lockFile]);
            return false;
        }

        if (!flock($this->lockHandle, LOCK_EX | LOCK_NB)) {
            fclose($this->lockHandle);
            $this->lockHandle = null;
            return false;
        }

        // Write PID and timestamp
        fwrite($this->lockHandle, json_encode([
            'pid'       => getmypid(),
            'started_at' => date('Y-m-d H:i:s'),
            'force'     => $this->isForce,
        ]));

        $this->logger->info('Lock acquired', ['pid' => getmypid()]);

        return true;
    }

    /**
     * Check if the process that created the lock file is still running
     */
    private function isLockOwnerAlive(): bool
    {
        $contents = @file_get_contents($this->lockFile);

        if ($contents === false) {
            return false;
        }

        $data = json_decode($contents, true);

        if (!isset($data['pid']) || !is_int($data['pid'])) {
            return false;
        }

        $pid = $data['pid'];

        // Linux: check /proc/{pid} and verify it's the same script
        if (PHP_OS_FAMILY === 'Linux') {
            if (!is_dir("/proc/{$pid}")) {
                return false;
            }

            $cmdline = @file_get_contents("/proc/{$pid}/cmdline");

            if ($cmdline === false) {
                return false;
            }

            return str_contains($cmdline, 'cron.php');
        }

        // Fallback: kill -0
        if (function_exists('posix_kill')) {
            return posix_kill($pid, 0);
        }

        $output = @shell_exec("kill -0 {$pid} 2>&1");

        return $output === null || trim($output) === '';
    }

    /**
     * Release the lock
     */
    private function releaseLock(): void
    {
        if ($this->lockHandle !== null) {
            flock($this->lockHandle, LOCK_UN);
            fclose($this->lockHandle);
        }

        @unlink($this->lockFile);
        $this->lockHandle = null;

        $this->logger->info('Lock released');
    }

    /**
     * Check if the service is in running state
     */
    private function isServiceRunning(): bool
    {
        $state = $this->db->fetchOne('SELECT service_state FROM system_state WHERE id = 1');

        if (!$state) {
            $this->logger->warning('System state not found, assuming started');
            return true;
        }

        return $state['service_state'] === 'started';
    }

    /**
     * Record cron run start in registry
     */
    private function recordCronStart(): int
    {
        return $this->db->insert(
            'INSERT INTO cron_registry (started_at, status) VALUES (datetime(\'now\'), ?)',
            ['running']
        );
    }

    /**
     * Record cron run finish in registry
     */
    private function recordCronFinish(int $cronId): void
    {
        $this->db->execute(
            'UPDATE cron_registry SET 
                finished_at = datetime(\'now\'),
                status = ?,
                repos_processed = ?,
                errors_count = ?,
                log_output = ?
             WHERE id = ?',
            [
                $this->errorsCount > 0 ? 'completed_with_errors' : 'completed',
                $this->reposProcessed,
                $this->errorsCount,
                implode("\n", $this->errorLog),
                $cronId,
            ]
        );
    }

    /**
     * Process the update queue
     */
    private function processQueue(): void
    {
        $queueProcessor = new QueueProcessor(
            $this->db,
            $this->logger,
            $this->console,
            $this->isVerbose,
            $this->isForce,
            $this->isDebug
        );

        $result = $queueProcessor->process();

        $this->reposProcessed = $result['processed'];
        $this->errorsCount    = $result['errors'];
        $this->errorLog       = $result['error_log'];
    }
}
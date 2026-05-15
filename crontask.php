<?php

declare(strict_types=1);

/**
 * GRASP Cron Task Runner
 *
 * Entry point for scheduled repository synchronization.
 * Run every minute via cron:
 *   * * * * * php /opt/grasp/crontask.php >> /opt/grasp/logs/cron.log 2>&1
 *
 * Supports flags:
 *   --verbose   Enable verbose console output
 *   --force     Force sync next repository even if not scheduled
 */

use App\Config;
use App\Logger;
use App\CronTasks\CronRunner;

// ============================================
// Bootstrap
// ============================================

require_once __DIR__ . '/vendor/autoload.php';

// Parse CLI arguments
$options = getopt('', ['verbose', 'force']);

$isVerbose = isset($options['verbose']);
$isForce   = isset($options['force']);

// ============================================
// Initialize Loggers
// ============================================

// Database logger - logs to grasp.log
$logger = new AppLogger('cron');

// Console logger - logs to STDOUT with colors (only in verbose mode)
$console = new AppLogger('console');

if ($isVerbose) {
    $console->enable();
}

// ============================================
// Global Exception Handler
// ============================================

set_exception_handler(function (\Throwable $e) use ($logger, $console) {
    $message = sprintf(
        "[CRITICAL] Unhandled exception: %s in %s:%d\n%s",
        $e->getMessage(),
        $e->getFile(),
        $e->getLine(),
        $e->getTraceAsString()
    );

    $logger->error($message);
    $console->error($message);

    exit(1);
});

// ============================================
// Run
// ============================================

try {
    $config = Config::getInstance();

    $console->info('══════════════════════════════════════');
    $console->info('  GRASP Cron Task Runner');
    $console->info('  Started: ' . date('Y-m-d H:i:s'));

    if ($isVerbose) {
        $console->info('  Mode: VERBOSE');
    }

    if ($isForce) {
        $console->warn('  Mode: FORCE (will process next repo regardless of schedule)');
    }

    $console->info('══════════════════════════════════════');

    // Run the cron tasks
    $runner = new CronRunner($logger, $console, $isVerbose, $isForce);
    $result = $runner->run();

    $console->info('──────────────────────────────────────');
    $console->info("  Finished: " . date('Y-m-d H:i:s'));
    $console->info("  Repos processed: {$result['processed']}");
    $console->info("  Errors: {$result['errors']}");
    $console->info("  Status: {$result['status']}");
    $console->info('══════════════════════════════════════');

    exit($result['errors'] > 0 ? 1 : 0);

} catch (\Throwable $e) {
    $logger->error('Cron task failed to initialize', [
        'error' => $e->getMessage(),
        'file'  => $e->getFile(),
        'line'  => $e->getLine(),
    ]);

    if ($isVerbose) {
        echo "\033[0;31m[FATAL] {$e->getMessage()}\033[0m\n";
        echo "{$e->getTraceAsString()}\n";
    }

    exit(1);
}

#!/usr/bin/env php
<?php
/**
 * GRASP CLI — командная утилита
 *
 * Usage:
 *   php grasp.php export <repo> [--out=path] [--format=zip|tar|tar.gz]
 *   php grasp.php help
 */

declare(strict_types=1);

if (!defined("START_TIME")) { define("START_TIME", microtime(true)); }
if (!defined("IS_PRODUCTION")) { define("IS_PRODUCTION", !is_file(__DIR__ . '/composer.lock')); }

if (IS_PRODUCTION) {
    require_once __DIR__ . '/grasp.phar';
} else {
    require_once __DIR__ . '/vendor/autoload.php';
}

use App\Tasks\ConsoleTasks;

$command = $argv[1] ?? null;

if (!$command || in_array($command, ['--help', '-h', 'help', '--version'], true)) {
    ConsoleTasks::showMainHelp();
    exit;
}

$configPath = $_SERVER['APP_CONFIG'] ?? getenv('APP_CONFIG') ?: __DIR__ . '/_config.php';
\App\App::init([$configPath]);

match ($command) {
    'export' => ConsoleTasks::cmdExport(array_slice($argv, 2)),
    'clone'  => ConsoleTasks::cmdClone(array_slice($argv, 2)),
    default  => ConsoleTasks::showMainHelp("Unknown command: {$command}"),
};

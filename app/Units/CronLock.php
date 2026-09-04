<?php

declare(strict_types=1);

namespace App\Units;

use App\App;

/**
 * Cron lock file helpers
 *
 * Единая проверка «жив ли крон-процесс» через lock-файл.
 * Используется при вычислении статуса сервиса (SystemController)
 * и в CLI-командах (ConsoleTasks::cmdCleanup).
 *
 * lockTimeout применяется, когда в lock-файле нет читаемого PID
 * (файл создан, но содержимое не распознано — считаем lock свежим,
 * если его возраст меньше таймаута).
 */
class CronLock
{
    public static function getLockFile(): string
    {
        return App::fromConfig('cron.lock_file', '/tmp/grasp_cron.lock');
    }

    public static function isHeld(): bool
    {
        $lockFile = self::getLockFile();

        if (!is_file($lockFile)) {
            return false;
        }

        $contents = @file_get_contents($lockFile);
        $data     = $contents !== false ? json_decode($contents, true) : null;

        if (!is_array($data) || !isset($data['pid']) || !is_int($data['pid'])) {
            return (time() - filemtime($lockFile)) < (int)App::fromConfig('cron.lock_timeout', 300);
        }

        $pid = $data['pid'];

        if (PHP_OS_FAMILY !== 'Linux') {
            return function_exists('posix_kill') ? posix_kill($pid, 0) : true;
        }

        if (!is_dir("/proc/{$pid}")) {
            return false;
        }

        $cmdline = @file_get_contents("/proc/{$pid}/cmdline");

        return $cmdline !== false && str_contains($cmdline, 'cron.php');
    }
}
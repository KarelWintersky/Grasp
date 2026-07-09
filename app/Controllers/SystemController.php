<?php

declare(strict_types=1);

namespace App\Controllers;

use App\App;

/**
 * System Controller
 *
 * статус и управление сервисом
 *
 * Provides system status and state management.
 */
class SystemController extends BaseController
{
    /**
     * Get system status and statistics.
     *
     * Service state is computed from cron_registry:
     *   - no cron runs in last 10 min → frozen
     *   - last run had errors → error
     *   - otherwise → started
     */
    public function status(): never
    {
        if (!(bool) App::fromConfig('cron.enabled', true)) {
            $serviceState = 'stopped';
        } else {
            $serviceState = $this->db->fetchValue(
                "SELECT CASE
                    WHEN MAX(started_at) IS NULL
                      OR  MAX(started_at) <= datetime('now', '-10 minutes')
                    THEN 'frozen'
                    WHEN (
                        SELECT COALESCE(SUM(errors_count), 0)
                        FROM cron_registry
                        WHERE started_at > datetime('now', '-10 minutes')
                    ) > 0 THEN 'error'
                    ELSE 'running'
                 END
                 FROM cron_registry"
            ) ?? 'frozen';
        }

        $lastCronRun = $this->db->fetchOne(
            'SELECT started_at, finished_at, status, repos_processed, errors_count 
             FROM cron_registry ORDER BY started_at DESC LIMIT 1'
        );

        $repoStateCounts = $this->db->fetchAll(
            'SELECT repo_state, COUNT(*) as count FROM repositories GROUP BY repo_state ORDER BY count DESC'
        );

        $queueStats = $this->db->fetchOne(
            'SELECT 
                COUNT(*) AS total,
                SUM(CASE WHEN queue_type = \'clone\' THEN 1 ELSE 0 END) AS clone_count,
                SUM(CASE WHEN queue_type = \'update\' THEN 1 ELSE 0 END) AS update_count
             FROM update_queue'
        );

        $totalRepos = array_sum(array_column($repoStateCounts, 'count'));

        $stats = [
            'total_repos'     => $totalRepos,
            'repos_by_state'  => $repoStateCounts,
            'queue_size'      => (int) ($queueStats['total'] ?? 0),
            'queue_clone'     => (int) ($queueStats['clone_count'] ?? 0),
            'queue_update'    => (int) ($queueStats['update_count'] ?? 0),
            'total_groups'    => $this->db->fetchValue('SELECT COUNT(*) FROM `groups`'),
            'total_tags'      => $this->db->fetchValue('SELECT COUNT(*) FROM tags'),
            'total_events'    => $this->db->fetchValue('SELECT COUNT(*) FROM events'),
            'db_size_bytes'   => $this->db->getDatabaseSize(),
            'last_cron_run'   => $lastCronRun,
        ];

        $this->success([
            'service_state'  => $serviceState,
            'app_version'    => App::getVersion(),
            'git_backend'      => [
                'enabled'  => App::isGitBackendEnabled(),
                'base_url' => App::getGitBackendBaseUrl(),
            ],
            'allow_server_info'     => (bool) App::fromConfig('frontend.allow_server_info'),
            'show_detailed_logs'    => (bool) App::fromConfig('frontend.show_detailed_logs'),
            'polling_interval'      => (int) App::fromConfig('frontend.polling_interval'),
            'tabs'  =>  [
                'overview'  =>  (bool) App::fromConfig('frontend.tabs.overview'),
                'queue'     =>  (bool) App::fromConfig('frontend.tabs.queue'),
                'events'    =>  (bool) App::fromConfig('frontend.tabs.events'),
                'groups'    =>  (bool) App::fromConfig('frontend.tabs.groups'),
                'tags'      =>  (bool) App::fromConfig('frontend.tabs.tags'),
            ],
            'stats'                 => $stats,
        ]);
    }

    /**
     * Health endpoint — system diagnostics
     */
    public function health(): never
    {
        $storagePath = App::fromConfig('storage.path', '/opt/grasp/storage');

        $totalRepos = (int) $this->db->fetchValue('SELECT COUNT(*) FROM repositories');

        $storageSize = $this->getDirectorySize($storagePath);
        $diskFree    = (int)disk_free_space($storagePath);
        $diskTotal   = (int)disk_total_space($storagePath);

        $this->success([
            'repositories' => [
                'total' => $totalRepos,
            ],
            'storage' => [
                'path'                => $storagePath,
                'used_bytes'          => $storageSize,
                'used'                => self::formatBytes($storageSize),
                'disk_free_bytes'     => $diskFree,
                'disk_free'           => self::formatBytes($diskFree),
                'disk_total_bytes'    => $diskTotal,
                'disk_total'          => self::formatBytes($diskTotal),
                'disk_used_percent'   => $diskTotal > 0
                    ? round(($diskTotal - $diskFree) / $diskTotal * 100, 1) : 0,
            ],
            'memory' => [
                'server_total'        => self::formatBytes($this->getServerMemoryTotal()),
                'server_available'    => self::formatBytes($this->getServerMemoryAvailable()),
                'php_current'         => self::formatBytes(memory_get_usage(true)),
                'php_peak'            => self::formatBytes(memory_get_peak_usage(true)),
            ],
        ]);
    }

    /**
     * Get total size of a directory using `du`
     */
    private function getDirectorySize(string $path): int
    {
        if (!is_dir($path)) {
            return 0;
        }

        $output = @shell_exec("du -sb " . escapeshellarg($path) . " 2>/dev/null");

        if ($output === null || $output === false) {
            return 0;
        }

        $parts = explode("\t", $output);

        return (int) ($parts[0] ?? 0);
    }

    /**
     * Get total server RAM from /proc/meminfo
     */
    private function getServerMemoryTotal(): int
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            return 0;
        }

        $meminfo = @file_get_contents('/proc/meminfo');

        if ($meminfo === false || !preg_match('/^MemTotal:\s+(\d+)\s+kB/im', $meminfo, $m)) {
            return 0;
        }

        return (int) $m[1] * 1024;
    }

    /**
     * Get available server RAM from /proc/meminfo
     */
    private function getServerMemoryAvailable(): int
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            return 0;
        }

        $meminfo = @file_get_contents('/proc/meminfo');

        if ($meminfo === false || !preg_match('/^MemAvailable:\s+(\d+)\s+kB/im', $meminfo, $m)) {
            return 0;
        }

        return (int) $m[1] * 1024;
    }

    /**
     * Format bytes to human-readable string
     */
    private static function formatBytes(int $bytes): string
    {
        if ($bytes === 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = (int) floor(log($bytes, 1024));

        return round($bytes / (1024 ** $i), 1) . ' ' . $units[$i];
    }

}
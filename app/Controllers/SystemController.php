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
    private const ALLOWED_ACTIONS = ['start', 'stop', 'freeze'];

    private const STATE_MAP = [
        'start'  => 'started',
        'stop'   => 'stopped',
        'freeze' => 'frozen',
    ];

    /**
     * Get system status and statistics
     */
    public function status(): never
    {
        $systemState = $this->db->fetchOne('SELECT * FROM system_state WHERE id = 1');

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
            'last_cron_run'   => $this->db->fetchOne(
                'SELECT started_at, finished_at, status, repos_processed, errors_count 
                 FROM cron_registry ORDER BY started_at DESC LIMIT 1'
            ),
        ];

        $this->success([
            'service_state'  => $systemState['service_state'] ?? 'unknown',
            'service_uptime' => $systemState['updated_at'] ?? null,
            'app_version'    => App::getVersion(),
            'git_backend'    => [
                'enabled'  => App::isGitBackendEnabled(),
                'base_url' => App::getGitBackendBaseUrl(),
            ],
            'stats'          => $stats,
        ]);
    }

    /**
     * Change system state
     */
    public function changeState(): never
    {
        $data = $this->getJsonBody();
        $this->validateRequired($data, ['action']);

        $action = $data['action'];

        if (!in_array($action, self::ALLOWED_ACTIONS)) {
            $this->error(
                "Invalid action: {$action}. Allowed: " . implode(', ', self::ALLOWED_ACTIONS),
                422
            );
        }

        $newState = self::STATE_MAP[$action];

        $this->db->execute(
            'UPDATE system_state SET service_state = ? WHERE id = 1',
            [$newState]
        );

        // Trigger will record the event automatically

        $this->success([
            'service_state' => $newState,
        ], "System state changed to: {$newState}");
    }
}
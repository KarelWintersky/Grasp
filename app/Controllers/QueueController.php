<?php

declare(strict_types=1);

namespace App\Controllers;

/**
 * Queue Controller
 *
 * управление очередью обновлений
 *
 * Manages the update/clone queue.
 */
class QueueController extends BaseController
{
    /**
     * Get update queue
     */
    public function list(): never
    {
        $queue = $this->db->fetchAll('SELECT * FROM v_queue');
        $this->success($queue);
    }

    /**
     * Trigger repository update/clone
     */
    public function trigger(array $vars): never
    {
        $repoId = (int) $vars['repo_id'];

        $repo = $this->db->fetchOne('SELECT * FROM repositories WHERE id = ?', [$repoId]);
        $this->validateExists($repo, 'Repository', $repoId);

        // Determine if clone or update
        $needsClone = ($repo['repo_state'] === 'need_clone' ||
            $repo['repo_state'] === 'cloning_error' ||
            empty($repo['date_cloned_initial']));

        $queueType = $needsClone ? 'clone' : 'update';
        $newState  = $needsClone ? 'need_clone' : 'need_update';

        // Upsert into queue (SQLite syntax)
        $this->db->execute(
            'INSERT INTO update_queue (repo_id, queue_type) VALUES (?, ?)
             ON CONFLICT(repo_id) DO UPDATE SET 
                 queue_type = excluded.queue_type,
                 priority = priority + 1,
                 attempts = 0,
                 scheduled_at = NULL,
                 created_at = datetime(\'now\')',
            [$repoId, $queueType]
        );

        // Update repo state
        $this->db->execute(
            'UPDATE repositories SET repo_state = ? WHERE id = ?',
            [$newState, $repoId]
        );

        $this->recordEvent($newState, $repoId,
            "Manual trigger: {$queueType} for {$repo['user_name']}/{$repo['repo_name']}");

        $this->success(null, "Repository queued for {$queueType}");
    }

    /**
     * Cancel queue item
     */
    public function cancel(array $vars): never
    {
        $repoId = (int) $vars['repo_id'];

        $queueItem = $this->db->fetchOne('SELECT * FROM update_queue WHERE repo_id = ?', [$repoId]);
        $this->validateExists($queueItem, 'Queue item', $repoId);

        $this->db->execute('DELETE FROM update_queue WHERE repo_id = ?', [$repoId]);

        // Reset repo state if it was waiting
        $repo = $this->db->fetchOne('SELECT repo_state FROM repositories WHERE id = ?', [$repoId]);
        if (in_array($repo['repo_state'], ['need_clone', 'need_update'])) {
            $this->db->execute(
                'UPDATE repositories SET repo_state = ? WHERE id = ?',
                ['frozen', $repoId]
            );
        }

        $this->success(null, 'Queue item cancelled');
    }
}
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
    public function trigger(int $repo_id): never
    {
        $repo = $this->db->fetchOne(
            'SELECT repo_state, date_cloned_initial, user_name, repo_name FROM repositories WHERE id = ?',
            [$repo_id]
        );
        $this->validateExists($repo, 'Repository', $repo_id);

        // Determine if clone or update
        $pendingClone = ($repo['repo_state'] === 'pending_clone' ||
            $repo['repo_state'] === 'cloning_error' ||
            empty($repo['date_cloned_initial']));

        $queueType = $pendingClone ? 'clone' : 'update';
        $newState  = $pendingClone ? 'pending_clone' : 'pending_update';

        // Upsert queue + update state + event (атомарно)
        $this->db->transaction(function() use ($repo_id, $queueType, $newState, $repo): void {
            $this->db->upsert('update_queue', [
                'repo_id'    => $repo_id,
                'queue_type' => $queueType,
            ], 'repo_id', [
                'queue_type'   => '=excluded',
                'priority'     => '=expr:priority + 1',
                'attempts'     => 0,
                'scheduled_at' => null,
                'created_at'   => '=now',
            ]);

            $this->db->execute(
                'UPDATE repositories SET repo_state = ? WHERE id = ?',
                [$newState, $repo_id]
            );

            $this->recordEvent($newState, $repo_id,
                "Manual trigger: {$queueType} for {$repo['user_name']}/{$repo['repo_name']}");
        });

        $this->success(null, "Repository queued for {$queueType}");
    }

    /**
     * Cancel queue item
     */
    public function cancel(int $repo_id): never
    {
        $queueItem = $this->db->fetchOne('SELECT 1 AS id FROM update_queue WHERE repo_id = ?', [$repo_id]);
        $this->validateExists($queueItem, 'Queue item', $repo_id);

        $this->db->transaction(function() use ($repo_id): void {
            $repo = $this->db->fetchOne('SELECT repo_state FROM repositories WHERE id = ?', [$repo_id]);

            $this->db->execute('DELETE FROM update_queue WHERE repo_id = ?', [$repo_id]);

            if ($repo && in_array($repo['repo_state'], ['pending_clone', 'pending_update'])) {
                $this->db->execute(
                    'UPDATE repositories SET repo_state = ? WHERE id = ?',
                    ['frozen', $repo_id]
                );
            }
        });

        $this->success(null, 'Queue item cancelled');
    }
}
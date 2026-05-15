<?php

declare(strict_types=1);

namespace App\Controllers;

/**
 * Event Controller
 *
 * чтение событий
 *
 * Provides read-only access to system events.
 */
class EventController extends BaseController
{
    /**
     * List events with optional filtering
     */
    public function list(): never
    {
        $limit  = min((int) ($this->query('limit', 100)), 500);
        $type   = $this->query('type');
        $repoId = $this->query('repo_id');

        $sql    = 'SELECT * FROM v_events WHERE 1=1';
        $params = [];

        if ($type) {
            $sql .= ' AND event_type = ?';
            $params[] = $type;
        }

        if ($repoId) {
            $sql .= ' AND repo_id = ?';
            $params[] = (int) $repoId;
        }

        $sql .= ' ORDER BY datetime DESC LIMIT ?';
        $params[] = $limit;

        $events = $this->db->fetchAll($sql, $params);
        $this->success($events);
    }

    /**
     * Get single event
     */
    public function get(array $vars): never
    {
        $id = (int) $vars['id'];

        $event = $this->db->fetchOne('SELECT * FROM v_events WHERE id = ?', [$id]);
        $this->validateExists($event, 'Event', $id);

        $this->success($event);
    }
}
<?php

declare(strict_types=1);

namespace App\Controllers;

/**
 * Group Controller
 *
 * управление группами
 *
 * Handles CRUD operations for repository groups.
 */
class GroupController extends BaseController
{
    /**
     * List all groups
     */
    public function list(): never
    {
        $groups = $this->db->fetchAll('SELECT * FROM groups ORDER BY title');
        $this->success($groups);
    }

    /**
     * Get single group with repo count
     */
    public function get(int $id): never
    {
        $group = $this->db->fetchOne('SELECT * FROM groups WHERE id = ?', [$id]);
        $this->validateExists($group, 'Group', $id);

        $group['repo_count'] = $this->db->fetchValue(
            'SELECT COUNT(*) FROM repositories WHERE repo_group = ?',
            [$id]
        );

        $this->success($group);
    }

    /**
     * Create new group
     */
    public function create(): never
    {
        $data = $this->getJsonBody();
        $this->validateRequired($data, ['alias', 'title']);

        // Check unique alias
        $existing = $this->db->fetchOne('SELECT id FROM groups WHERE alias = ?', [$data['alias']]);
        if ($existing) {
            $this->error("Group with alias '{$data['alias']}' already exists", 409);
        }

        $id = $this->db->insert(
            'INSERT INTO groups (alias, title, default_update_period) VALUES (?, ?, ?)',
            [
                $data['alias'],
                $data['title'],
                $data['default_update_period'] ?? '7d',
            ]
        );

        $this->recordEvent('group_created', null, "Group created: {$data['title']}");

        $group = $this->db->fetchOne('SELECT * FROM groups WHERE id = ?', [$id]);
        $this->success($group, 'Group created', 201);
    }

    /**
     * Update group
     */
    public function update(int $id): never
    {
        $group = $this->db->fetchOne('SELECT * FROM groups WHERE id = ?', [$id]);
        $this->validateExists($group, 'Group', $id);

        $data = $this->getJsonBody();

        $allowed = ['alias', 'title', 'default_update_period'];
        $updates = [];
        $params  = [];

        foreach ($allowed as $field) {
            if (!array_key_exists($field, $data)) {
                continue;
            }

            // Check unique alias if changing
            if ($field === 'alias' && $data['alias'] !== $group['alias']) {
                $existing = $this->db->fetchOne(
                    'SELECT id FROM groups WHERE alias = ? AND id != ?',
                    [$data['alias'], $id]
                );
                if ($existing) {
                    $this->error("Group with alias '{$data['alias']}' already exists", 409);
                }
            }

            $updates[] = "{$field} = ?";
            $params[]  = $data[$field];
        }

        if (empty($updates)) {
            $this->error('No valid fields to update', 422);
        }

        $params[] = $id;
        $this->db->execute(
            'UPDATE groups SET ' . implode(', ', $updates) . ' WHERE id = ?',
            $params
        );

        $updated = $this->db->fetchOne('SELECT * FROM groups WHERE id = ?', [$id]);
        $this->success($updated, 'Group updated');
    }

    /**
     * Delete group (repos revert to NULL group)
     */
    public function delete(int $id): never
    {
        $group = $this->db->fetchOne('SELECT * FROM groups WHERE id = ?', [$id]);
        $this->validateExists($group, 'Group', $id);

        $this->db->execute('DELETE FROM groups WHERE id = ?', [$id]);

        $this->recordEvent('group_deleted', null, "Group deleted: {$group['title']}");

        $this->success(null, 'Group deleted');
    }
}
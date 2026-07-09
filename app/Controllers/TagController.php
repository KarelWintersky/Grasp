<?php

declare(strict_types=1);

namespace App\Controllers;

/**
 * Tag Controller
 *
 * управление тегами
 *
 * Handles CRUD operations for tags.
 */
class TagController extends BaseController
{
    /**
     * List all tags
     */
    public function list(): never
    {
        $rows = $this->db->fetchAll("SELECT DISTINCT tags FROM repositories WHERE tags != ''");
        $tags = [];
        foreach ($rows as $row) {
            foreach (explode('|', $row['tags']) as $name) {
                $name = trim($name);
                if ($name !== '') {
                    $tags[$name] = true;
                }
            }
        }
        $tags = array_keys($tags);
        sort($tags);
        $this->success($tags);
    }

    /**
     * Create new tag
     */
    public function create(): never
    {
        $data = $this->getJsonBody();
        $this->validateRequired($data, ['name']);

        $existing = $this->db->fetchOne('SELECT id FROM tags WHERE name = ?', [$data['name']]);
        if ($existing) {
            $this->error("Tag '{$data['name']}' already exists", 409);
        }

        $id = $this->db->insert('INSERT INTO tags (name) VALUES (?)', [$data['name']]);

        $this->success([
            'id' => $id,
            'name' => $data['name'],
        ], 'Tag created', 201);
    }

    /**
     * Delete tag
     */
    public function delete(string $name): never
    {
        $tag = $this->db->fetchOne('SELECT * FROM tags WHERE name = ?', [$name]);
        $this->validateExists($tag, 'Tag', $name);

        $this->db->execute('DELETE FROM tags WHERE name = ?', [$name]);

        $this->success(null, 'Tag deleted');
    }
}
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
        $tags = $this->db->fetchAll('SELECT * FROM tags ORDER BY name');
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

        $tag = $this->db->fetchOne('SELECT * FROM tags WHERE id = ?', [$id]);
        $this->success($tag, 'Tag created', 201);
    }

    /**
     * Delete tag
     */
    public function delete(array $vars): never
    {
        $name = $vars['name'];

        $tag = $this->db->fetchOne('SELECT * FROM tags WHERE name = ?', [$name]);
        $this->validateExists($tag, 'Tag', $name);

        $this->db->execute('DELETE FROM tags WHERE name = ?', [$name]);

        $this->success(null, 'Tag deleted');
    }
}
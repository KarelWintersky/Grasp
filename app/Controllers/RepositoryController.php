<?php

declare(strict_types=1);

namespace App\Controllers;

use App\App;
use App\Service\ServiceFactory;
use App\Units\FS;
use App\Units\UrlParser;
use InvalidArgumentException;

/**
 * Repository Controller
 *
 * вся логика работы с репозиториями
 *
 * Handles all CRUD operations for Git repositories.
 */
class RepositoryController extends BaseController
{
    /**
     * List all repositories with optional filtering
     */
    public function list(): never
    {
        $group  = $this->query('group');
        $tag    = $this->query('tag');
        $state  = $this->query('state');
        $search = $this->query('search');

        $sql    = 'SELECT * FROM v_repositories WHERE 1=1';
        $params = [];

        if ($group) {
            $sql .= ' AND repo_group = ?';
            $params[] = (int) $group;
        }

        if ($state) {
            $sql .= ' AND repo_state = ?';
            $params[] = $state;
        }

        if ($tag) {
            $sql .= ' AND (tags LIKE ? OR tags LIKE ? OR tags LIKE ?)';
            $params[] = "{$tag}|%";
            $params[] = "%|{$tag}|%";
            $params[] = "%|{$tag}";
        }

        if ($search) {
            $sql .= ' AND (user_name LIKE ? OR repo_name LIKE ? OR description LIKE ? OR remote_url LIKE ?)';
            $searchParam = "%{$search}%";
            $params[] = $searchParam;
            $params[] = $searchParam;
            $params[] = $searchParam;
            $params[] = $searchParam;
        }

        $sql .= ' ORDER BY repo_group NULLS FIRST, user_name, repo_name';

        $repos = $this->db->fetchAll($sql, $params);
        $this->success($repos);
    }

    /**
     * Get single repository with events
     */
    public function get(int $id): void
    {
        try {
            $repo = $this->db->fetchOne('SELECT * FROM v_repositories WHERE id = ?', [$id]);
            $this->validateExists($repo, 'Repository', $id);

            $repo['recent_events'] = $this->db->fetchAll(
                'SELECT * FROM events WHERE repo_id = ? ORDER BY datetime DESC LIMIT 20',
                [$id]
            );

            $this->success($repo);
        } catch (\Exception $e) {
            var_dump($e);
        }
    }

    /**
     * Create new repository
     */
    public function create(): never
    {
        $data = $this->getJsonBody();
        $this->validateRequired($data, ['remote_url']);

        // Parse URL
        try {
            $parsed = new UrlParser($data['remote_url']);
        } catch (InvalidArgumentException $e) {
            $this->error("Invalid repository URL: {$e->getMessage()}", 422);
        }

        $normalizedUrl = $parsed->getNormalizedUrl();

        // Check duplicate
        $existing = $this->db->fetchOne(
            'SELECT id FROM repositories WHERE remote_url = ?',
            [$normalizedUrl]
        );

        if ($existing) {
            $this->error('Repository with this URL already exists', 409, [
                'existing_id' => $existing['id'],
            ]);
        }

        // Determine update interval
        $updateInterval = $data['update_interval'] ?? null;
        $repoGroup = !empty($data['repo_group']) ? (int) $data['repo_group'] : null;

        if ($repoGroup && !$updateInterval) {
            $group = $this->db->fetchOne('SELECT default_update_period FROM groups WHERE id = ?', [$repoGroup]);
            $updateInterval = $group['default_update_period'] ?? null;
        }

        if (!$updateInterval) {
            $updateInterval = App::config('default_update_interval') ?? '7d';
        }

        // Insert repository + queue + event (атомарно)
        $repoId = $this->db->transaction(function() use ($normalizedUrl, $parsed, $data, $repoGroup, $updateInterval): int {
            $id = $this->db->insert(
                'INSERT INTO repositories 
                    (remote_url, user_name, repo_name, git_service, storage_path, 
                     description, comment, repo_group, tags, update_interval, repo_state)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $normalizedUrl,
                    $parsed->getUserName(),
                    $parsed->getRepoName(),
                    $parsed->getGitService(),
                    $parsed->getStoragePath(),
                    $data['description'] ?? '',
                    $data['comment'] ?? '',
                    $repoGroup,
                    $data['tags'] ?? '',
                    $updateInterval,
                    'pending_clone',
                ]
            );

            $this->db->insert(
                'INSERT OR IGNORE INTO update_queue (repo_id, queue_type) VALUES (?, ?)',
                [$id, 'clone']
            );

            $this->recordEvent('pending_clone', $id,
                "Repository added: {$parsed->getFullName()}");

            return $id;
        });

        // Best-effort: fetch description from service API (вне транзакции — HTTP)
        $this->fetchRemoteDescription($repoId, $parsed, $data);

        $this->success(['id' => $repoId], 'Repository added successfully', 201);
    }

    /**
     * Update repository metadata
     */
    public function update(int $id): never
    {
        $repo = $this->db->fetchOne('SELECT * FROM repositories WHERE id = ?', [$id]);
        $this->validateExists($repo, 'Repository', $id);

        $data = $this->getJsonBody();

        $allowed = ['description', 'comment', 'repo_group', 'tags', 'update_interval'];
        $updates = [];
        $params  = [];

        foreach ($allowed as $field) {
            if (!array_key_exists($field, $data)) {
                continue;
            }

            // Validate group exists if changing
            if ($field === 'repo_group' && !empty($data[$field])) {
                $group = $this->db->fetchOne('SELECT id FROM `groups` WHERE id = ?', [(int) $data[$field]]);
                $this->validateExists($group, 'Group', $data[$field]);
            }

            $updates[] = "{$field} = ?";
            $params[]  = $data[$field];
        }

        if (empty($updates)) {
            $this->error('No valid fields to update', 422);
        }

        $params[] = $id;
        $this->db->execute(
            'UPDATE repositories SET ' . implode(', ', $updates) . ' WHERE id = ?',
            $params
        );

        $this->recordEvent('metadata_updated', $id, 'Repository metadata updated');

        $updated = array_merge($repo, array_intersect_key($data, array_flip($allowed)));
        $this->success($updated, 'Repository updated');
    }

    /**
     * Delete repository
     */
    public function delete(int $id = 0):never
    {
        $repo = $this->db->fetchOne('SELECT * FROM repositories WHERE id = ?', [$id]);
        $this->validateExists($repo, 'Repository', $id);

        $repoName = "{$repo['user_name']}/{$repo['repo_name']}";

        if (App::config('features.deferred_delete')) {
            $this->db->transaction(function() use ($id, $repoName): void {
                $this->db->execute(
                    'UPDATE repositories SET repo_state = ? WHERE id = ?',
                    ['pending_delete', $id]
                );
                $this->db->execute('DELETE FROM update_queue WHERE repo_id = ?', [$id]);
                $this->recordEvent('pending_delete', $id, "Repository marked for deletion: {$repoName}");
            });

            $this->success(null, 'Repository marked for deletion');
        } else {
            // удаляем на месте (файловую систему — вне транзакции)
            $storagePath = App::config('storage.path') ?? '/opt/grasp/storage';
            $fullPath = rtrim($storagePath, '/') . '/' . ltrim($repo['storage_path'] ?? '', '/');

            $filesDeleted = true;
            if (is_dir($fullPath)) {
                try {
                    FS::deleteDirectory($fullPath);
                } catch (\Throwable $e) {
                    $this->logger->error('Failed to delete repository files', [
                        'path'  => $fullPath,
                        'error' => $e->getMessage(),
                    ]);
                    $filesDeleted = false;
                }
            }

            // Удаляем из БД + событие (атомарно)
            $this->db->transaction(function() use ($id, $repoName, $filesDeleted): void {
                $this->db->execute('DELETE FROM repositories WHERE id = ?', [$id]);

                $this->recordEvent('deleted', null,
                    $filesDeleted
                        ? "Repository deleted: {$repoName}"
                        : "Repository deleted from DB but files may remain: {$repoName}");
            });

            $this->success(null, $filesDeleted ? 'Repository deleted' : 'Repository deleted (files cleanup may be needed)');
        }
    }

    /**
     * Try to fetch description from remote service (best effort)
     */
    private function fetchRemoteDescription(int $repoId, UrlParser $parsed, array $data): void
    {
        // Skip if user already provided a description
        if (!empty($data['description'])) {
            return;
        }

        try {
            $factory = new ServiceFactory();
            $service = $factory->createFromParser($parsed);
            $description = $service->fetchDescription(
                $parsed->getUserName(),
                $parsed->getRepoName()
            );

            if ($description) {
                $this->db->execute(
                    'UPDATE repositories SET description = ? WHERE id = ?',
                    [$description, $repoId]
                );
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to fetch remote description', [
                'url'   => $parsed->getNormalizedUrl(),
                'error' => $e->getMessage(),
            ]);
        }
    }


}
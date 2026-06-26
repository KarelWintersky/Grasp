<?php

declare(strict_types=1);

namespace App\Controllers;

use App\App;
use App\AppDatabase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Base Controller
 *
 * общая функциональность: JSON-ответы, валидация, чтение тела запроса, запись событий
 *
 * Provides common functionality for all API controllers.
 */
abstract class BaseController
{
    public AppDatabase $db;

    public LoggerInterface $logger;

    public function __construct(?LoggerInterface $logger = null)
    {
        $this->db     = App::db();
        $this->logger = is_null($logger) ? new NullLogger() : $logger;
    }

    /**
     * Send JSON success response
     */
    protected function success(mixed $data = null, string $message = 'OK', int $statusCode = 200): never
    {
        $this->jsonResponse([
            'status'  => 'ok',
            'message' => $message,
            'data'    => $data,
        ], $statusCode);
    }

    /**
     * Send JSON error response
     */
    protected function error(string $message, int $statusCode = 400, mixed $data = null): never
    {
        $this->jsonResponse([
            'status'  => 'error',
            'message' => $message,
            'data'    => $data,
        ], $statusCode);
    }

    /**
     * Send JSON response and exit
     */
    protected function jsonResponse(array $payload, int $statusCode = 200): never
    {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, PATCH, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Accept');

        $payload['access_level'] = App::getAccessLevel();

        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    /**
     * Get JSON body from request
     */
    protected function getJsonBody(): array
    {
        $input = file_get_contents('php://input');

        if (empty($input)) {
            return [];
        }

        $data = json_decode($input, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->error('Invalid JSON: ' . json_last_error_msg(), 400);
        }

        return $data ?? [];
    }

    /**
     * Get query parameter
     */
    protected function query(string $key, mixed $default = null): mixed
    {
        return $_GET[$key] ?? $default;
    }

    /**
     * Validate required fields in data array
     */
    protected function validateRequired(array $data, array $required): void
    {
        foreach ($required as $field) {
            if (!isset($data[$field]) || (is_string($data[$field]) && trim($data[$field]) === '')) {
                $this->error("Missing required field: {$field}", 422);
            }
        }
    }

    /**
     * Validate that a record exists
     */
    protected function validateExists(?array $record, string $entity, int|string $id): void
    {
        if ($record === null) {
            $this->error("{$entity} not found: {$id}", 404);
        }
    }

    /**
     * Record an event in the events table
     */
    protected function recordEvent(string $type, ?int $repoId, string $message, string $description = ''): void
    {
        try {
            $this->db->insert(
                'INSERT INTO events (event_type, repo_id, message, description) VALUES (?, ?, ?, ?)',
                [$type, $repoId, $message, $description]
            );
        } catch (\Throwable $e) {
            $this->logger->error('Failed to record event', [
                'type'  => $type,
                'repo_id' => $repoId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
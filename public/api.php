<?php

declare(strict_types=1);

/**
 * GRASP API Entry Point
 *
 * All requests to /api/* are routed here by nginx.
 * Base path /api is stripped — routes are defined without the prefix.
 */

use Arris\AppRouter;
use Arris\Exceptions\AppRouterHandlerError;
use Arris\Exceptions\AppRouterMethodNotAllowedException;
use Arris\Exceptions\AppRouterNotFoundException;
use App\Config;
use App\Logger;
use App\Controllers\RepositoryController;
use App\Controllers\GroupController;
use App\Controllers\TagController;
use App\Controllers\QueueController;
use App\Controllers\EventController;
use App\Controllers\SystemController;

// ============================================
// Bootstrap
// ============================================

require_once __DIR__ . '/../vendor/autoload.php';

$config = Config::getInstance();
$logger = Logger::getInstance();

date_default_timezone_set($config->get('timezone', 'UTC'));

// ============================================
// CORS Preflight
// ============================================

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, PATCH, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Accept');
    http_response_code(204);
    exit;
}

// ============================================
// Helpers (thin wrappers — logic is in Controllers)
// ============================================

function json_error(string $message, int $code = 400): never {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => $message]);
    exit;
}

// ============================================
// Router
// ============================================

try {
    AppRouter::init(
        allowEmptyHandlers: false,
    );

    // --- Root (health check) ---
    AppRouter::get('/', function () {
        header('Content-Type: application/json');
        echo json_encode([
            'status'  => 'ok',
            'message' => 'GRASP API is running',
            'data'    => [
                'name'    => 'GRASP API',
                'version' => '1.0.0',
            ],
        ]);
        exit;
    }, 'api.root');

    // ============================================
    // Repositories
    // ============================================
    $repo = new RepositoryController();

    AppRouter::get('/repositories',                    [$repo, 'list'],    'repositories.list');
    AppRouter::post('/repositories',                   [$repo, 'create'],  'repositories.create');
    AppRouter::get('/repositories/{id:\d+}',           [$repo, 'get'],     'repositories.get');
    AppRouter::patch('/repositories/{id:\d+}',         [$repo, 'update'],  'repositories.update');
    AppRouter::delete('/repositories/{id:\d+}',        [$repo, 'delete'],  'repositories.delete');

    // ============================================
    // Groups
    // ============================================
    $group = new GroupController();

    AppRouter::get('/groups',                          [$group, 'list'],    'groups.list');
    AppRouter::post('/groups',                         [$group, 'create'],  'groups.create');
    AppRouter::get('/groups/{id:\d+}',                 [$group, 'get'],     'groups.get');
    AppRouter::patch('/groups/{id:\d+}',               [$group, 'update'],  'groups.update');
    AppRouter::delete('/groups/{id:\d+}',              [$group, 'delete'],  'groups.delete');

    // ============================================
    // Tags
    // ============================================
    $tag = new TagController();

    AppRouter::get('/tags',                            [$tag, 'list'],    'tags.list');
    AppRouter::post('/tags',                           [$tag, 'create'],  'tags.create');
    AppRouter::delete('/tags/{name}',                  [$tag, 'delete'],  'tags.delete');

    // ============================================
    // Update Queue
    // ============================================
    $queue = new QueueController();

    AppRouter::get('/queue/update',                            [$queue, 'list'],     'queue.list');
    AppRouter::post('/queue/update/trigger/{repo_id:\d+}',    [$queue, 'trigger'],  'queue.trigger');
    AppRouter::delete('/queue/update/{repo_id:\d+}',          [$queue, 'cancel'],   'queue.cancel');

    // ============================================
    // Events
    // ============================================
    $event = new EventController();

    AppRouter::get('/events',                          [$event, 'list'],    'events.list');
    AppRouter::get('/events/{id:\d+}',                 [$event, 'get'],     'events.get');

    // ============================================
    // System
    // ============================================
    $system = new SystemController();

    AppRouter::get('/system/status',                   [$system, 'status'],       'system.status');
    AppRouter::post('/system/status',                  [$system, 'changeState'],  'system.change_state');

    // ============================================
    // Dispatch
    // ============================================

    AppRouter::dispatch();

} catch (AppRouterNotFoundException $e) {
    json_error('Endpoint not found', 404);

} catch (AppRouterMethodNotAllowedException $e) {
    json_error('Method not allowed', 405);

} catch (AppRouterHandlerError $e) {
    $logger->error('Handler error', $e->getError());
    json_error('Internal server error', 500);

} catch (\Throwable $e) {
    $logger->error('Unhandled exception', [
        'message' => $e->getMessage(),
        'file'    => $e->getFile(),
        'line'    => $e->getLine(),
    ]);
    json_error('Internal server error', 500);
}
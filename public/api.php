<?php

declare(strict_types=1);

/**
 * GRASP API Entry Point
 *
 * Thin router configuration using Arris.AppRouter.
 * All business logic is in /app/Controllers/.
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
// Router
// ============================================

try {
    AppRouter::init(
        logger: $logger,
        allowEmptyHandlers: false,
    );

    // --- Root ---
    AppRouter::get('/api.php[/]', function () {
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

    // --- Repositories ---
    $repoController = new RepositoryController();

    AppRouter::get('/api.php/repositories',         [$repoController, 'list'],   'repositories.list');
    AppRouter::post('/api.php/repositories',         [$repoController, 'create'], 'repositories.create');
    AppRouter::get('/api.php/repositories/{id:\d+}', [$repoController, 'get'],    'repositories.get');
    AppRouter::patch('/api.php/repositories/{id:\d+}', [$repoController, 'update'], 'repositories.update');
    AppRouter::delete('/api.php/repositories/{id:\d+}', [$repoController, 'delete'], 'repositories.delete');

    // --- Groups ---
    $groupController = new GroupController();

    AppRouter::get('/api.php/groups',               [$groupController, 'list'],   'groups.list');
    AppRouter::post('/api.php/groups',               [$groupController, 'create'], 'groups.create');
    AppRouter::get('/api.php/groups/{id:\d+}',      [$groupController, 'get'],    'groups.get');
    AppRouter::patch('/api.php/groups/{id:\d+}',     [$groupController, 'update'], 'groups.update');
    AppRouter::delete('/api.php/groups/{id:\d+}',    [$groupController, 'delete'], 'groups.delete');

    // --- Tags ---
    $tagController = new TagController();

    AppRouter::get('/api.php/tags',                  [$tagController, 'list'],    'tags.list');
    AppRouter::post('/api.php/tags',                 [$tagController, 'create'],  'tags.create');
    AppRouter::delete('/api.php/tags/{name}',        [$tagController, 'delete'],  'tags.delete');

    // --- Queue ---
    $queueController = new QueueController();

    AppRouter::get('/api.php/queue/update',                             [$queueController, 'list'],    'queue.list');
    AppRouter::post('/api.php/queue/update/trigger/{repo_id:\d+}',     [$queueController, 'trigger'], 'queue.trigger');
    AppRouter::delete('/api.php/queue/update/{repo_id:\d+}',           [$queueController, 'cancel'],  'queue.cancel');

    // --- Events ---
    $eventController = new EventController();

    AppRouter::get('/api.php/events',               [$eventController, 'list'], 'events.list');
    AppRouter::get('/api.php/events/{id:\d+}',      [$eventController, 'get'],  'events.get');

    // --- System ---
    $systemController = new SystemController();

    AppRouter::get('/api.php/system/status',         [$systemController, 'status'],       'system.status');
    AppRouter::post('/api.php/system/status',        [$systemController, 'changeState'],  'system.change_state');

    // ============================================
    // Dispatch
    // ============================================

    AppRouter::dispatch();

} catch (AppRouterNotFoundException $e) {
    $errorData = $e->getError();
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode([
        'status'  => 'error',
        'message' => $errorData['message'] ?? 'Endpoint not found',
        'data'    => [
            'method' => $_SERVER['REQUEST_METHOD'],
            'uri'    => $_SERVER['REQUEST_URI'],
        ],
    ]);

} catch (AppRouterMethodNotAllowedException $e) {
    $errorData = $e->getError();
    http_response_code(405);
    header('Content-Type: application/json');
    echo json_encode([
        'status'  => 'error',
        'message' => $errorData['message'] ?? 'Method not allowed',
        'data'    => [
            'allowed_methods' => $errorData['allowed_methods'] ?? [],
        ],
    ]);

} catch (AppRouterHandlerError $e) {
    $errorData = $e->getError();
    $logger->error('AppRouter handler error', $errorData);
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'status'  => 'error',
        'message' => 'Internal server error',
    ]);

} catch (\Throwable $e) {
    $logger->error('Unhandled exception', [
        'message' => $e->getMessage(),
        'file'    => $e->getFile(),
        'line'    => $e->getLine(),
    ]);
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'status'  => 'error',
        'message' => 'Internal server error',
    ]);
}
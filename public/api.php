<?php

declare(strict_types=1);

/**
 * GRASP API Entry Point
 *
 * All requests to /api/* are routed here by nginx.
 * Base path /api is stripped — routes are defined without the prefix.
 */

use App\App;
use Arris\AppLogger;
use Arris\AppRouter;
use Arris\Exceptions\AppRouterHandlerError;
use Arris\Exceptions\AppRouterMethodNotAllowedException;
use Arris\Exceptions\AppRouterNotFoundException;
use App\Controllers\RepositoryController;
use App\Controllers\GroupController;
use App\Controllers\TagController;
use App\Controllers\QueueController;
use App\Controllers\EventController;
use App\Controllers\SystemController;

// ============================================
// Bootstrap
// ============================================

if (!defined("START_TIME")) { define("START_TIME", microtime(true)); }
if (!defined("IS_PRODUCTION")) { define("IS_PRODUCTION", !is_file(__DIR__ . '/../composer.lock')); }

if (IS_PRODUCTION) {
    require_once __DIR__ . '/../grasp.phar';
} else {
    require_once __DIR__ . '/../vendor/autoload.php';
}

$configPath = $_SERVER['APP_CONFIG'] ?? getenv('APP_CONFIG') ?: __DIR__ . '/../_config.php';
App::init([$configPath]);
$logger = AppLogger::scope('main');

date_default_timezone_set(App::config('timezone') ?? 'UTC');

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

    AppRouter::group(prefix: '/api', callback: function () {
        AppRouter::get('/info', function () {
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
        AppRouter::get('/repositories',                    [RepositoryController::class, 'list'],    'repositories.list');
        AppRouter::post('/repositories',                   [RepositoryController::class, 'create'],  'repositories.create');
        AppRouter::get('/repositories/{id:\d+}',           [RepositoryController::class, 'get'],     'repositories.get');
        AppRouter::patch('/repositories/{id:\d+}',         [RepositoryController::class, 'update'],  'repositories.update');
        AppRouter::delete('/repositories/{id:\d+}',        [RepositoryController::class, 'delete'],  'repositories.delete');

        AppRouter::get('/groups',                          [GroupController::class, 'list'],    'groups.list');
        AppRouter::post('/groups',                         [GroupController::class, 'create'],  'groups.create');
        AppRouter::get('/groups/{id:\d+}',                 [GroupController::class, 'get'],     'groups.get');
        AppRouter::patch('/groups/{id:\d+}',               [GroupController::class, 'update'],  'groups.update');
        AppRouter::delete('/groups/{id:\d+}',              [GroupController::class, 'delete'],  'groups.delete');

        // ============================================
        // Tags
        // ============================================
        AppRouter::get('/tags',                            [TagController::class, 'list'],    'tags.list');
        AppRouter::post('/tags',                           [TagController::class, 'create'],  'tags.create');
        AppRouter::delete('/tags/{name}',                  [TagController::class, 'delete'],  'tags.delete');

        // ============================================
        // Update Queue
        // ============================================
        AppRouter::get('/queue/update',                            [QueueController::class, 'list'],     'queue.list');
        AppRouter::post('/queue/update/trigger/{repo_id:\d+}',    [QueueController::class, 'trigger'],  'queue.trigger');
        AppRouter::delete('/queue/update/{repo_id:\d+}',          [QueueController::class, 'cancel'],   'queue.cancel');

        // ============================================
        // Events
        // ============================================
        AppRouter::get('/events',                          [EventController::class, 'list'],    'events.list');
        AppRouter::get('/events/{id:\d+}',                 [EventController::class, 'get'],     'events.get');

        // ============================================
        // System
        // ============================================
        AppRouter::get('/system/status',                   [SystemController::class, 'status'],       'system.status');
        AppRouter::post('/system/status',                  [SystemController::class, 'changeState'],  'system.change_state');
    });

    /*var_dump(
        AppRouter\Helper::dumpRoutingRulesWeb(AppRouter::getRoutingRules())
    );
    die;*/

    AppRouter::dispatch();

} catch (AppRouterNotFoundException $e) {
    json_error('Endpoint not found: ' . $e->getMessage(), 404);

} catch (AppRouterMethodNotAllowedException $e) {
    json_error('Method not allowed: ' . $e->getMessage() , 405);

} catch (AppRouterHandlerError $e) {
    $logger->error('Handler error', $e->getError());
    json_error('Internal server error: ' . $e->getMessage(), 500);

} catch (\Throwable $e) {
    $logger->error('Unhandled exception', [
        'message' => $e->getMessage(),
        'file'    => $e->getFile(),
        'line'    => $e->getLine(),
    ]);
    json_error('Internal server error: ' . $e->getMessage(), 500);
}
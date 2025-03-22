<?php

use App\App;
use App\Exceptions\AccessDeniedException;
use App\Exceptions\FileNotFoundException;
use Arris\AppLogger;
use Arris\AppRouter;
use Arris\Exceptions\AppRouterHandlerError;
use Arris\Exceptions\AppRouterMethodNotAllowedException;
use Arris\Exceptions\AppRouterNotFoundException;

define('ENGINE_START_TIME', microtime(true));
if (!session_id()) @session_start();

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

try {
    require_once __DIR__ . '/../vendor/autoload.php';
    $config = require_once __DIR__ . '/../config.php';

    App::init($config);

    $firewall = new Arris\Toolkit\FireWall(false);
    $firewall->addWhiteList(config('ACCESS.ALLOWED_IP'));
    $firewall->validate($ip = $firewall->getIP());

    if ($firewall->isForbidden()) {
        throw new AccessDeniedException("Your IP <u>{$ip}</u> not allowed here.");
    }

    AppRouter::init(AppLogger::scope('router'));
    AppRouter::setDefaultNamespace("\App");

    // главная страница: там или форма логина, или обработки медиа.
    // AppRouter::get('/', [ \App\Controllers\MainController::class, 'view_main_page'], 'view_main_page');

    // AppRouter::get('/auth/login[/]', [\App\Controllers\AuthController::class, 'form_login'], 'form_login');
    // AppRouter::post('/auth/login[/]', [\App\Controllers\AuthController::class, 'callback_login'], 'callback_login');
    // AppRouter::get('/auth/logout[/]', [\App\Controllers\AuthController::class, 'callback_logout'], 'callback_logout');

    AppRouter::group(
        before: [ \App\Middlewares\AuthMiddleware::class, 'check_auth'],
        callback: static function() {

            AppRouter::group(
                prefix: '/admin',
                callback: function () {
                    // главная страница админки
                    // AppRouter::get('/', [\App\Controllers\AdminController::class, 'view_admin_page'], 'view_admin_page');
                }
            );
        },
    );

    // dd(AppRouter::getRoutingRules());

    AppRouter::dispatch();

    App::$template->assign("_auth", config('auth'));
    App::$template->assign("_config", config());
    App::$template->assign("_request", $_REQUEST);

} catch (AppRouterHandlerError $e) {

    AppLogger::scope('main')->notice("AppRouter::InvalidRoute", [ $e->getMessage(), $e->getInfo() ] );
    http_response_code(500);
    dd($e);

} catch (AccessDeniedException $e) {

    AppLogger::scope('auth')->error($e->getMessage(), [ $_SERVER['REQUEST_URI'], config('auth.ipv4') ] );
    App::$template->assign('message', $e->getMessage());
    App::$template->setTemplate("_errors/403.tpl");

} catch (AppRouterNotFoundException $e) {

    AppLogger::scope('main')->notice("AppRouter::NotFound", [ $e->getMessage(), $e->getInfo() ] );
    http_response_code(404);
    App::$template->setTemplate("_errors/404.tpl");

} catch (AppRouterMethodNotAllowedException $e){

    AppLogger::scope('main')->notice("AppRouter::NotAllowed", [ $e->getMessage(), $e->getInfo() ] );
    http_response_code(405);
    dd($e);

} catch (FileNotFoundException $e) {

    AppLogger::scope('download')->error("File:NotFound", [ $_GET, $e->getMessage(), $e->getFile(), $e->getLine() ]);
    echo $e->getMessage();
    App::$template->setRenderType(\Arris\Presenter\Template::CONTENT_TYPE_404);

}  catch (\PDOException|\RuntimeException|\JsonException|SmartyException|\Exception $e) {
    AppLogger::scope('main')->error("Other:", [ $e->getMessage(), $e->getFile(), $e->getLine() ]);
    http_response_code(500);

    App::$template->assign('exception', $e);
    App::$template->assign("error", $e->getCode());
    App::$template->assign("error_message", $e->getMessage());

    App::$template->setTemplate("_errors/500.tpl");
} finally {
    $render = App::$template->render();

    if (!empty($render)) {
        App::$template->headers->send();
        echo $render;
    }
}

// logSiteUsage( AppLogger::scope('site_usage'));

if (App::$template->isRedirect()) {
    App::$template->makeRedirect();
}

exit;
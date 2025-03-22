<?php

namespace App\Middlewares;

use App\App;
use App\Exceptions\AccessDeniedException;
use Arris\AppRouter;

class AuthMiddleware
{
    public function check_auth($uri, $route_info)
    {
        if ((bool)config('ACCESS.AUTH') === false) {
            return true;
        }

        if (!App::$auth_driver->isLoggedIn()) {
            $callback = AppRouter::getRouter('callback_login');
            throw new AccessDeniedException(<<<AUTH_LOST
Вы не авторизованы. <br><br>Возможно, истекла сессия авторизации. <br><br>
<a href="{$callback}"> Перейти на страницу входа </a> <br>
AUTH_LOST
            );
        }
        return false;
    }

}
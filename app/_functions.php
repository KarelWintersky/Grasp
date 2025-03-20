<?php

use App\App;

/**
 * @param array|string $key
 * @param $value [optional]
 * @return string|array|bool|mixed|null
 */
function config(array|string $key = '', $value = null): mixed
{
    $app = App::factory();

    if (!is_null($value) && !empty($key)) {
        $app->setConfig($key, $value);
        return true;
    }

    // Для инициализации мы передаем репозиторий, но не конфиг.
    if ($key instanceof \Arris\Core\Dot) {
        $app->setConfig('', $value);
    }

    if (is_array($key)) {
        foreach ($key as $k => $v) {
            $app->setConfig($k, $v);
        }
        return true;
    }

    if (empty($key)) {
        return $app->getConfig();
    }

    return $app->getConfig($key);
}

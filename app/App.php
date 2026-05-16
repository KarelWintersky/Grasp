<?php

namespace App;

use Arris\AppLogger;

class App
{
    public static AppConfig $config;

    public static AppDatabase $db;

    public static function init(array $config = []): void
    {
        self::$config = new AppConfig($config);

        AppLogger::init('GRASP', options: [
            'default_logfile_path'  =>  self::$config->get('logs.path')
        ]);

        AppLogger::addScope('main');
        AppLogger::addScope('cron');
        AppLogger::addScope('database');

        self::$db = new AppDatabase(AppLogger::scope('database'));
    }

}
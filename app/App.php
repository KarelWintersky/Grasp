<?php

declare(strict_types=1);

namespace App;

use Arris\App as ArrisApp;
use Arris\AppLogger;

class App extends ArrisApp
{
    private static ?AppDatabase $_db = null;
    private static string $accessLevel = 'admin';

    public static function setAccessLevel(string $level): void
    {
        self::$accessLevel = $level;
    }

    public static function getAccessLevel(): string
    {
        return self::$accessLevel;
    }

    protected function getDefaultConfig(): array
    {
        return [
            'database'  =>  [
                'driver'    =>  'sqlite',
                'host'      =>  '/opt/grasp/db/grasp.sqlite'
            ],

            'storage'   =>  [
                'path'      =>  '/opt/grasp/storage'
            ],

            'logs'      =>  [
                'path'      =>  '/opt/grasp/logs'
            ],

            'cron'      =>  [
                'lock_file'     =>  '/tmp/grasp_cron.lock',
                'lock_timeout'  =>  300,
                'max_per_run'   =>  3,
                'retry_delay'   =>  300
            ],

            'features'  =>  [
                'deferred_delete'   =>  false
            ],

            'http_timeout'  => 30,

            'timezone'         => 'Europe/Moscow',

            'default_update_interval' => '7d',

            'git'       =>  [
                'binary'    =>  '/usr/bin/git',
                'timeout'   =>  300
            ],

            'github'    =>  [
                'api_base'      =>  'https://api.github.com',
                'api_timeout'   =>  15,
                'web_base'      =>  'https://github.com',
                'max_retries'   =>  3,
                'token'         =>  ''
            ],
            'logging'   =>  [
                'main'      =>  true,
                'database'  =>  false,
                'cron'      =>  false
            ],
            'access'    =>  [
                'admin_ips' => ['127.0.0.1', "192.168.111.1/24", '::1' ],
                'view_ips'  => ["192.168.111.1/24", '0.0.0.0/0', '::/0'],
            ],
        ];
    }

    public static function init(array $config = []): void
    {
        static::getInstance($config);

        AppLogger::init('GRASP', options: [
            'default_logfile_path'  =>  static::config('logs.path'),
        ]);

        AppLogger::addScope('main', scope_logging_enabled: App::config('logging.main'));
        AppLogger::addScope('cron', scope_logging_enabled: App::config('logging.cron'));
        AppLogger::addScope('database', scope_logging_enabled: App::config('logging.database'));

        self::$_db = new AppDatabase(AppLogger::scope('database'));
    }

    public static function db(): AppDatabase
    {
        return self::$_db;
    }

    /**
     * Получает версию из первой строчки файла _version
     * На проде он "вшит" в phar-файл по пути vendor/_version
     * На DEV его нет и подставляется строчка 'DEV'
     *
     * @return string
     */
    public static function getVersion(): string
    {
        $path = __DIR__ . '/../vendor/_version';
        $lines = file_exists($path) ? file($path, FILE_IGNORE_NEW_LINES) : [];
        return trim($lines[0] ?? 'DEV');
    }
}

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
                'host'      =>  '/opt/grasp/db/grasp.sqlite',
                // For PostgreSQL/MySQL:
                'port'      =>  5432,
                'dbname'    =>  'grasp',
                'user'      =>  'grasp',
                'password'  =>  '',
                'charset'   =>  'utf8mb4',
            ],

            'storage'   =>  [
                'path'      =>  '/opt/grasp/storage'
            ],

            'logs'      =>  [
                'path'      =>  '/opt/grasp/logs'
            ],

            'logging'   =>  [
                'main'      =>  true,
                'database'  =>  false,
                'cron'      =>  false
            ],

            'cron'      =>  [
                'enabled'        =>  true,
                'lock_file'      =>  '/tmp/grasp_cron.lock',
                'lock_timeout'   =>  300,
                'lock_check_pid' =>  true,
                'max_per_run'    =>  3,
                'retry_delay'    =>  300
            ],

            'frontend'  =>  [
                'tabs'  =>  [
                    'overview'  =>  true,
                    'queue'     =>  true,
                    'events'    =>  true,
                    'groups'    =>  true,
                    'tags'      =>  true,
                ],
                'deferred_delete'      =>  false,
                'allow_server_info'    =>  false, // показывать ли кнопку "Информация о сервере
                'show_detailed_logs'   =>  false, // показывать ли подробные логи на странице "События" на фронте?
                'polling_interval'     =>  30000, // мс, интервал опроса фронтендом
                'queue_lookahead'      =>  '1h',  // "Глубина" показа событий. Если суффикс не задан - трактуется как кол-во секунд.
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

            'access'    =>  [
                'admin_ips' => ['127.0.0.1', "192.168.111.1/24", '::1' ],
                'view_ips'  => ["192.168.111.1/24", '0.0.0.0/0', '::/0'],
            ],

            'git_http_backend'  =>  [
                'enabled'               =>  false,
                'info_ref_auto_update'  =>  true,
                'base_url'              =>  'http://grasp.local/git',
            ],
        ];
    }

    public static function init(array $config = []): void
    {
        static::getInstance($config);

        AppLogger::init('GRASP', options: [
            'default_logfile_path'  =>  static::config('logs.path'),
        ]);

        AppLogger::addScope('main', scope_logging_enabled: App::fromConfig('logging.main', false));
        AppLogger::addScope('cron', scope_logging_enabled: App::fromConfig('logging.cron', false));
        AppLogger::addScope('database', scope_logging_enabled: App::fromConfig('logging.database', false));

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
    public static function isGitBackendEnabled(): bool
    {
        return (bool) static::config('git_http_backend.enabled');
    }

    public static function getGitBackendBaseUrl(): string
    {
        return static::config('git_http_backend.base_url') ?? '/git';
    }

    public static function getVersion(): string
    {
        $path = __DIR__ . '/../vendor/_version';
        $lines = file_exists($path) ? file($path, FILE_IGNORE_NEW_LINES) : [];
        return trim($lines[0] ?? 'DEV');
    }
}

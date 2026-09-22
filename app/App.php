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
        return AppConfig::getDefaultConfig();
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

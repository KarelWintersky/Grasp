<?php

namespace App;

use App\Units\Auth;
use Arris\AppLogger;
use Arris\Cache\Cache;
use Arris\Core\Dot;
use Arris\Database\DBWrapper;
use Arris\DelightAuth\Auth\Role;
use Arris\Path;
use Arris\Presenter\FlashMessages;
use Arris\Presenter\Template;
use PDO;

class App extends \Arris\App
{
    /**
     * @var Dot
     */
    public static Dot $config;

    /**
     * @var DBWrapper|PDO
     */
    public static DBWrapper|PDO $pdo;

    /**
     * @var Template
     */
    public static Template $template;

    /**
     * @var Path
     */
    public static Path $path_install;

    /**
     * @var FlashMessages
     */
    public static FlashMessages $flash;

    /**
     * @var Units\Auth
     */
    public static Auth $auth;

    public static \Arris\DelightAuth\Auth\Auth $auth_driver;

    public static function init(array $config)
    {
        self::$config = App::factory($config)->getConfig(); //@todo: да, так можно и нужно!

        App::$path_install = new Path(config('PATH.INSTALL'));

        if (config('DATABASE.TYPE') === 'sqlite') {
            self::initDatabaseSqlite();
        } else {
            self::initDatabaseMysql();
        }

        self::initRedis();
        self::initLogger();
        self::initPresenter();
        self::initAuth();
    }

    public static function initRedis()
    {
        $credentials_redis = config('REDIS');

        Cache::init([
            'enabled'   =>  $credentials_redis['ENABLED'],
            'database'  =>  $credentials_redis['DATABASE'],
        ], [], App::$pdo);
    }

    /**
     * @throws \SmartyException
     */
    public static function initPresenter()
    {
        self::$template = new Template();
        self::$template
            ->setTemplateDir(App::$path_install->join('public/templates')->toString())
            ->setCompileDir(App::$path_install->join('cache')->toString())
            ->setForceCompile(true)
            ->registerPlugin(Template::PLUGIN_MODIFIER, 'json_decode', 'json_decode')
            ->registerPlugin(Template::PLUGIN_MODIFIER, 'dd', 'dd')
            ->registerClass("Arris\AppRouter", "Arris\AppRouter");
        ;

        self::$flash = new FlashMessages();
        App::$template->assign("flash_messages", App::$flash->getMessage('flash', []));
    }

    public static function initLogger()
    {
        AppLogger::init('Graps', options: [
            'default_logfile_path'  =>  config('PATH.LOGS')
        ]);
        AppLogger::addScope('router');
        AppLogger::addScope('git');
    }

    public static function initAuth()
    {
        App::$auth_driver = new \Arris\DelightAuth\Auth\Auth(new \PDO(
            sprintf(
                "mysql:dbname=%s;host=%s;charset=utf8mb4",
                config('DATABASE.DATABASE'),
                config('DATABASE.HOSTNAME')
            ),
            config('DATABASE.USERNAME'),
            config('DATABASE.PASSWORD')
        ), dbTablePrefix: 'auth_');

        $auth = [
            'enabled'       =>  config('ACCESS.AUTH') == true,
            'id'            =>  App::$auth_driver->id(),
            'is_logged_in'  =>  App::$auth_driver->isLoggedIn(),
            'username'      =>  App::$auth_driver->getUsername(),
            'email'         =>  App::$auth_driver->getEmail(),
            'ipv4'          =>  \Arris\Helpers\Server::getIP(),

            'is_admin'      =>  App::$auth_driver->hasRole(Role::ADMIN),
        ];

        App::$auth->data = new Dot($auth);

        config('auth', $auth);
    }

    public static function initDatabaseMysql()
    {
        self::$pdo = new DBWrapper([
            'database'  =>  config('DATABASE.DATABASE'),
            'username'  =>  config('DATABASE.USERNAME'),
            'password'  =>  config('DATABASE.PASSWORD'),
            'charset'   =>  "utf8mb4",
            'charset_collate'   =>  "utf8mb4_unicode_ci"
        ]);
    }

    public static function initDatabaseSqlite()
    {
        self::$pdo = new PDO(sprintf('sqlite:%s', config('DATABASE.FILE')));
        self::$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        self::$pdo->exec("
            CREATE TABLE IF NOT EXISTS repositories (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                download_date TEXT,
                update_date TEXT,
                url TEXT,
                path TEXT,
                size INTEGER,
                repo_name TEXT,
                description TEXT,
                minutes INTEGER DEFAULT 360
            )
        ");
    }



}
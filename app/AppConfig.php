<?php

namespace App;

class AppConfig extends \Arris\AppConfig
{
    public static function getDefaultConfig():array
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
                'allow_repo_size'      =>  false, // считать и показывать размер репозитория на диске в карточке
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
}
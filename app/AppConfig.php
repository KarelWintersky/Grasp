<?php

namespace App;

use Noodlehaus\AbstractConfig;
use Noodlehaus\Config;

class AppConfig extends AbstractConfig
{
    public function __construct(array $data = [])
    {
        parent::__construct($data);
        $this->data = array_merge($this->getDefaults(), (new Config($data))->data);
    }

    protected function getDefaults(): array
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

            'http_timeout'  =>  30,

            'timezone'         => 'Europe/Moscow',

            'default_update_interval' => '7d',

            'git'       =>  [
                'binary'    =>  '/usr/bin/git',
                'timeout'   =>  300
            ],

            'github'    =>  [
                'api_base'      =>  'https://api.github.com',
                'api_timeout'   =>  15,

                'web_base'  =>  'https://github.com',

                'max_retries'   =>  3,

                'token'     =>  ''
            ],
        ];
    }

}
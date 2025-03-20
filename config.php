<?php

use Arris\Helpers\INI;
use Arris\Helpers\Strings;

const CONFIG_PATH = '/etc/grasp/';
const CONFIG_FILE = CONFIG_PATH . DIRECTORY_SEPARATOR . 'main.ini';
if (!is_readable(CONFIG_FILE)) {
    die("FATAL ERROR: " . CONFIG_FILE . " not readable!");
}
$ini = parse_ini_file(CONFIG_FILE, true);

$config = [
    'APP'   =>  [
        'DOMAIN'    =>  $ini['APP']['DOMAIN']
    ],
    'DATABASE'  =>  [
        'TYPE'      =>  'sqlite', // sqlite|mysql
        'FILE'      =>  __DIR__ . '/grasp.sqlite',
        'HOSTNAME'  =>  '127.0.0.1',
        'DATABASE'  =>  $ini['DATABASE']['DATABASE'],
        'USERNAME'  =>  $ini['DATABASE']['USERNAME'],
        'PASSWORD'  =>  $ini['DATABASE']['PASSWORD'],
    ],
    'PATH'      =>  [
        'INSTALL'   =>  __DIR__,
        'TEMPLATES' =>  __DIR__ . '/public/templates',
        'CACHE'     =>  __DIR__ . '/cache',
        'STORAGE'   =>  '/srv/gitbackup/',
        'ASSETS'    =>  __DIR__ . '/files',
        'LOGS'      =>  __DIR__ . '/logs',
        'RAWLOGS'   =>  __DIR__ . '/logs/raw_input'
    ],
    'REDIS'     =>  [
        'ENABLED'   =>  $ini['REDIS']['ENABLED'] ?? false,
        'DATABASE'  =>  $ini['REDIS']['DATABASE'] ?? 10
    ],
];

return $config;

Пример `_config.php`

```php
return [
    'database'  =>   [
        'host'      =>  __DIR__ . '/grasp.sqlite'
    ],
    'logs'      =>  [
        'path'      =>  __DIR__ . '/logs/'
    ],
    'storage'   =>  [
        'path'      =>  __DIR__ . '/storage/'
    ],
    'logging'   =>  [
        'database'  =>  false,
        'cron'      =>  true
    ],
];
```


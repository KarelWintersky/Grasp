# GRASP — Git Repository Aggregation & Storage Platform

Зеркалирование, отслеживание и управление Git-репозиториями с нескольких хостингов.
Bare-клоны с автоматическим обновлением по расписанию, REST API и SPA-фронтенд на ванильном JS.

## Возможности

- **Зеркалирование репозиториев** — bare `git clone` + `git fetch --all --prune` по расписанию
- **Несколько хостингов** — GitHub, GitLab, Bitbucket, Codeberg, Gitea, SourceHut, self-hosted
- **Обогащение метаданными** — авто-получение описания, языка, тем, лицензии из API хостинга
- **Обновление по расписанию** — интервалы на репозиторий (`1h`, `6h`, `1d`, `7d`, `30d`, `вручную`, `никогда`)
- **Ручной запуск** — форсировать обновление из UI или через cron `--force`
- **Машина состояний** — 11+ состояний репозитория (`pending_clone` → `cloning` → `pending_update` → ...) с логированием событий
- **Отложенное удаление** — мягкий delete с фоновой зачисткой
- **Состояние сервиса** — start/stop/freeze (крон проверяет перед запуском)
- **Группы и теги** — группировка репозиториев (с интервалом по умолчанию) и теги через `|`
- **Аудит событий** — автоматическая запись событий при каждом изменении состояния через триггеры БД
- **REST API** — 21 endpoint для полного CRUD + системные операции
- **SPA-фронтенд** — ванильный JS с дневной/ночной темой, toast-уведомлениями, адаптивной вёрсткой
- **Cron-оркестрация** — lock-файл, timeout, retry delay, макс. задач за запуск, реестр запусков
- **Парсер URL** — HTTPS, SSH, SCP, git:// — авто-определение хостинга

## Требования

- PHP 8.2+
- Расширения PHP: `pdo`, `curl`, `redis` (задекларировано, пока не используется)
- SQLite
- Git (`/usr/bin/git`)
- Composer
- nginx + PHP-FPM (для веб-доступа)

## Быстрый старт

```bash
# 1. Установка зависимостей
composer install --no-dev

# 2. Инициализация БД
php _setup.php

# 3. Опционально: seed с тестовыми данными
php _setup.php --seed

# 4. Настройка nginx (см. debian/nginx.conf)
#    корень на public/, /api/* — на api.php
```

## Конфигурация

Путь к конфигу определяется в порядке приоритета:

**Для веб-входа (api.php):**

1. Переменная окружения **`APP_CONFIG`** (nginx: `fastcgi_param APP_CONFIG`)
2. Файл **`_config.php`** в корне проекта

**Для CLI-скриптов (cron.php, _setup.php):**

1. Ключ **`--config=/path/to/file`**
2. Файл **`config.php`** в директории скрипта

### Структура конфига

```php
'database' => ['driver' => 'sqlite', 'host' => '/path/to/grasp.sqlite'],
'storage'  => ['path' => '/path/to/storage'],
'logs'     => ['path' => '/path/to/logs'],
'cron'     => [
    'lock_file'    => '/tmp/grasp_cron.lock',
    'lock_timeout' => 300,
    'max_per_run'  => 3,
    'retry_delay'  => 300,
],
'features' => ['deferred_delete' => false],
'http_timeout' => 30,
'timezone' => 'Europe/Moscow',
'default_update_interval' => '7d',
'git' => ['binary' => '/usr/bin/git', 'timeout' => 300],
'github' => ['token' => ''],
'logging' => ['database' => false, 'cron' => true],
```

Файлы конфига могут быть PHP (return array) или YAML.

## API Endpoints

Все маршруты с префиксом `/api`. Ответ — JSON.

| Метод | Путь | Описание |
|---|---|---|
| GET | `/api/info` | Информация о версии API |
| **Репозитории** | | |
| GET | `/api/repositories` | Список (фильтры: group, tag, state, search) |
| POST | `/api/repositories` | Создать (парсит URL, авто-описание) |
| GET | `/api/repositories/{id}` | Получить с последними событиями |
| PATCH | `/api/repositories/{id}` | Обновить метаданные |
| DELETE | `/api/repositories/{id}` | Удалить (немедленно или отложенно) |
| **Группы** | | |
| GET/POST | `/api/groups` | Список / Создать |
| GET/PATCH/DELETE | `/api/groups/{id}` | Получить / Обновить / Удалить |
| **Теги** | | |
| GET/POST | `/api/tags` | Список / Создать |
| DELETE | `/api/tags/{name}` | Удалить |
| **Очередь** | | |
| GET | `/api/queue/update` | Список очереди |
| POST | `/api/queue/update/trigger/{repo_id}` | Добавить в очередь (clone/update) |
| DELETE | `/api/queue/update/{repo_id}` | Отменить элемент очереди |
| **События** | | |
| GET | `/api/events` | Список (фильтры: type, repo_id, limit) |
| GET | `/api/events/{id}` | Получить событие |
| **Система** | | |
| GET | `/api/system/status` | Статус + статистика |
| POST | `/api/system/status` | Изменить состояние (start/stop/freeze) |

## Cron

Запускать каждую минуту:

```cron
* * * * * php /opt/grasp/cron.php >> /opt/grasp/logs/cron.log 2>&1
```

Опции:

| Флаг | Описание |
|---|---|
| `--config=PATH` | Путь к конфигу (по умолч. `./config.php`) |
| `--verbose` | Цветной вывод в консоль |
| `--force` | Форсировать синхронизацию (минуя расписание) |
| `--debug` | Показывать вывод git-команд |

Алгоритм работы крона:

1. Получение lock-файла (устаревает через 300 с)
2. Проверка состояния сервиса (выход если не `started`)
3. Запись запуска в `cron_registry`
4. Обработка отложенных удалений (если включено)
5. Планирование репозиториев с истёкшим `calculated_next_update`
6. Обработка до 3 элементов очереди (или 1 в force-режиме)
7. Обновление реестра запуска

## База данных

SQLite, 8 таблиц, 3 представления, 6 триггеров, 16 индексов.

Основные таблицы: `repositories`, `groups`, `tags`, `update_queue`, `events`, `cron_registry`, `system_state`, `repo_remotes`.

Инициализация:

```bash
php _setup.php              # безопасный режим (IF NOT EXISTS)
php _setup.php --force      # пересоздать все таблицы
php _setup.php --seed       # + тестовые данные
```

## nginx

```nginx
server {
    root /opt/grasp/public;
    index index.html;

    location /assets/ {
        expires 7d;
        add_header Cache-Control "public, immutable";
    }

    location / {
        try_files $uri $uri/ /index.html;
    }

    location /api/ {
        try_files $uri $uri/ /api.php?$query_string;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME  $document_root$fastcgi_script_name;
        fastcgi_param APP_CONFIG       /etc/grasp/config.yaml;
        fastcgi_pass unix:/run/php/php-fpm.sock;
    }
}
```

Полный пример — `debian/nginx.conf`.

## Фронтенд

SPA на ванильном JS:

- Браузер репозиториев с фильтрами по группе, тегу, состоянию и поиском
- Управление очередью с отображением приоритета
- Лог событий с цветовой маркировкой по типу
- Таблица групп
- Дневная/ночная тема (учитывает `prefers-color-scheme`, сохраняется в localStorage)
- Toast-уведомления
- URL hash routing
- Автообновление каждые 15 с

## Разработка

```bash
make setup_env     # создать storage/ и logs/
composer install   # установка PHP-зависимостей
pnpm install       # установка Node-зависимостей (сборка фронтенда)
gulp               # сборка фронтенда
php _setup.php --seed  # инициализация БД с тестовыми данными
php cron.php --verbose  # ручной запуск крона
```

## Сборка пакета

```bash
make build         # dpkg-buildpackage → .deb → /opt/grasp/
```

## Лицензия

GPL-3.0-or-later

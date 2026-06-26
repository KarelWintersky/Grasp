# Настройка git-http-backend через fcgiwrap

> Доступ к bare-репозиториям GRASP по протоколу HTTP(S) — `git clone`, `git fetch`, `git pull`.

## Установка

```bash
apt install fcgiwrap git
systemctl enable --now fcgiwrap
```

По умолчанию fcgiwrap слушает сокет `/run/fcgiwrap.socket`. Проверить:

```bash
systemctl status fcgiwrap
```

## Настройка nginx

Добавить location в server-блок GRASP (`/etc/nginx/sites-available/grasp.wintersky.ru`):

```nginx
location ~ ^/git(/.*) {
    # Доступ только по IP (см. access control)
    include /etc/nginx/includes/grasp-git-acl.conf;

    gzip off;

    # Для clone/fetch достаточно read-only доступа к репозиториям
    root /var/www.projects/GraspV3/storage;

    # fastcgi_params + свои параметры
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME  /usr/lib/git-core/git-http-backend;
    fastcgi_param GIT_PROJECT_ROOT $document_root;
    fastcgi_param GIT_HTTP_EXPORT_ALL "";
    fastcgi_param PATH_INFO        $1;
    fastcgi_param REMOTE_USER      $remote_user;

    fastcgi_pass unix:/run/fcgiwrap.socket;
}
```

### Пояснение параметров

| Параметр | Зачем |
|---|---|
| `GIT_PROJECT_ROOT` | Путь к директории с bare-репозиториями (storage GRASP) |
| `GIT_HTTP_EXPORT_ALL` | Разрешить чтение всех репозиториев (без неё нужен файл `git-daemon-export-ok` в каждом) |
| `PATH_INFO` | Передаёт путь от `/git/` до конца URL — git-http-backend разбирает его сам |
| `SCRIPT_FILENAME` | Указывает на сам бинарник git-http-backend |

## ACL по IP

Файл `/etc/nginx/includes/grasp-git-acl.conf`:

```nginx
# Разрешить доступ к git-репозиториям
allow 127.0.0.1;
allow 192.168.0.0/16;
allow 10.0.0.0/8;

# Твой внешний IP
allow 1.2.3.4;

deny all;
```

## Авто-обновление info/refs  (опционально)

В cron (каждую минуту или реже):

```bash
*/5 * * * * find /var/www.projects/GraspV3/storage -type d -name "*.git" -exec git --git-dir={} update-server-info \;
```

Или через cron GRASP — в `cron.php` уже есть обновление репозиториев, можно добавить вызов `update-server-info` после каждого fetch.

Проверить:

```bash
git --git-dir=/var/www.projects/GraspV3/storage/some/repo.git update-server-info
ls -la /var/www.projects/GraspV3/storage/some/repo.git/info/refs
```

## Проверка

```bash
git clone http://grasp.wintersky.ru/git/some/repo.git
```

## Конфигурация GRASP

В конфиге GRASP (`_config.php` / YAML) нужно включить git backend:

```php
'git_http_backend' => [
    'enabled'  => true,
    'base_url' => '/git',          // префикс URL, совпадает с location в nginx, точнее, что-то в духе: http://grasp.local/git
],
```

Параметр `base_url` может быть:
- относительным (`/git`) — если на том же домене
- абсолютным (`https://git.example.com/git`) — если на отдельном поддомене

По умолчанию `enabled: false` — clone URL не показывается в интерфейсе.
Статус отображается в About-модалке и в ответе `/api/system/status`.

## Примечания

- `storage` должен быть доступен на чтение nginx (пользователь, под которым выполняется nginx).
- `git-http-backend` **не поддерживает** push по HTTP без дополнительной настройки аутентификации. Для push используется SSH.
- В GRASP репозитории хранятся как `bare` — клонируются нормально, пушить напрямую в storage не надо (только через зеркалирование).

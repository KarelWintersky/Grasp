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

Добавить location в конфиг виртуального хоста GRASP:

```nginx
#
# ловим локейшен, указанный в git_http_backend->base_url 
#
location ~ ^/git(/.*) {
    # Доступ только по IP (см. access control)
    # include /etc/nginx/includes/grasp-git-acl.conf;

    gzip off;

    # Для clone/fetch достаточно read-only доступа к репозиториям
    root <путь к storage>;

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
Что важно, в `root` путь к storage на диске, и он может быть где угодно. Создавать симлинк оттуда в /public/storage НЕ НУЖНО.

### Пояснение параметров

| Параметр | Зачем |
|---|---|
| `GIT_PROJECT_ROOT` | Путь к директории с bare-репозиториями (storage GRASP) |
| `GIT_HTTP_EXPORT_ALL` | Разрешить чтение всех репозиториев (без неё нужен файл `git-daemon-export-ok` в каждом) |
| `PATH_INFO` | Передаёт путь от `/git/` до конца URL — git-http-backend разбирает его сам |
| `SCRIPT_FILENAME` | Указывает на сам бинарник git-http-backend |

## ACL по IP (опционально)

Файл `/etc/nginx/includes/grasp-git-acl.conf` или `/srv/grasp/grasp-git-acl.conf` 

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

## NB: Запуск nginx+fcgiwrap от кастомного пользователя

Кейс сервера Blacktower.Proxy, на котором всё работает от пользователя `arris`.

Если nginx (воркеры) запущен не от `www-data`, а от своего пользователя (например, `arris`), 
fcgiwrap по умолчанию создаёт сокет `/run/fcgiwrap.socket` от `www-data`, и nginx при переходе на роут /git/ получает `Permission denied`.

**Способ 1 — добавить пользователя nginx в группу www-data + открыть сокет группе:**

```bash
usermod -aG www-data arris
echo 'FCGIWRAP_EXTRA_OPTIONS="-M 0660"' >> /etc/default/fcgiwrap
systemctl restart fcgiwrap
```

`0660` — чтение и запись для владельца (`www-data`) и группы (`www-data`). `arris` в группе `www-data` — сможет читать сокет.

**Способ 2 — запустить fcgiwrap от того же пользователя (если fcgiwrap не нужен другим сайтам): РЕКОМЕНДОВАН**

Задать в `/etc/default/fcgiwrap`:
```bash
FCGIWRAP_USER=arris
FCGIWRAP_GROUP=arris
```

Перезапустить:
```bash
systemctl restart fcgiwrap
```

Сокет теперь будет создан от `arris:arris` — nginx, работающий от того же пользователя, сможет подключиться.

**Способ 3 — systemd override (самый прозрачный):**

```bash
systemctl edit fcgiwrap
```

Вписать:
```ini
[Service]
User=arris
Group=arris
```

Затем:
```bash
systemctl daemon-reload
systemctl restart fcgiwrap
```

**Проверка после любого способа:**
```bash
ls -la /run/fcgiwrap.socket
# srw-rw---- 1 arris arris 0 ... /run/fcgiwrap.socket  — если способ 2/3
# srw-rw---- 1 www-data www-data 0 ...                 — если способ 1, nginx должен быть в группе www-data
```

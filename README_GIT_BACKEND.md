# Настройка git-http-backend

> Доступ к bare-репозиториям GRASP по HTTP(S) — `git clone`, `git fetch`, `git pull`.

---

## 1. Установка fcgiwrap

```bash
apt install fcgiwrap
systemctl enable --now fcgiwrap
```

Проверить: `systemctl status fcgiwrap`. По умолчанию сокет: `/run/fcgiwrap.socket`.

---

## 2. Настройка nginx

Добавить location в конфиг виртуального хоста:

```nginx
location ~ ^/git(/.*) {
    gzip off;
    root /opt/grasp/storage;          # путь к storage из конфига GRASP

    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME  /usr/lib/git-core/git-http-backend;
    fastcgi_param GIT_PROJECT_ROOT $document_root;
    fastcgi_param GIT_HTTP_EXPORT_ALL "";
    fastcgi_param PATH_INFO        $1;
    fastcgi_param REMOTE_USER      $remote_user;

    fastcgi_pass unix:/run/fcgiwrap.socket;
}
```

> **Важно:** `root` указывает на директорию storage. Симлинк из storage в `public/` делать **не нужно**.

### Параметры fastcgi

| Параметр | Назначение |
|----------|-----------|
| `GIT_PROJECT_ROOT` | Путь к директории с bare-репозиториями (storage GRASP) |
| `GIT_HTTP_EXPORT_ALL` | Разрешить чтение всех репозиториев (без这个 опции нужен файл `git-daemon-export-ok` в каждом) |
| `PATH_INFO` | Путь от `/git/` до конца URL — git-http-backend разбирает его сам |
| `SCRIPT_FILENAME` | Путь к бинарнику git-http-backend |

---

## 3. ACL по IP (опционально)

Создать файл, например `/etc/nginx/includes/grasp-git-acl.conf`:

```nginx
allow 127.0.0.1;
allow 192.168.0.0/16;
allow 10.0.0.0/8;
deny all;
```

Раскомментировать `include` в location.

---

## 4. Конфигурация GRASP

В `config.php` добавить:

```php
'git_http_backend' => [
    'enabled'  => true,
    'base_url' => '/git',   // совпадает с префиксом location в nginx
],
```

- `enabled: true` — включает отображение clone-URL в интерфейсе
- `base_url` — показывается в UI. Если git на том же домене — достаточно `/git`. Если на отдельном поддомене — укажите полностью: `https://git.example.com/git`

---

## 5. Авто-обновление info/refs

Чтобы `git clone` работал, в каждом bare-репозитории должен быть актуальный файл `info/refs`. GRASP умеет обновлять его автоматически:

```php
'git_http_backend' => [
    'enabled'               => true,
    'info_ref_auto_update'  => true,    // запускать update-server-info после каждого clone/fetch
    'base_url'              => '/git',
],
```

Если не хотите включать авто-обновление в GRASP — добавьте в crontab:

```bash
*/5 * * * * find /opt/grasp/storage -type d -name "*.git" -exec git --git-dir={} update-server-info \;
```

---

## 6. Проверка

```bash
git clone http://<сервер>/git/<имя-репозитория>.git
```

---

## 7. Особый случай: nginx от кастомного пользователя

Если nginx запущен не от `www-data`, а от своего пользователя (например, `arris`), fcgiwrap по умолчанию создаёт сокет от `www-data` — nginx не сможет к нему подключиться.

### Способ 1 — добавить nginx в группу www-data

```bash
usermod -aG www-data arris
echo 'FCGIWRAP_EXTRA_OPTIONS="-M 0660"' >> /etc/default/fcgiwrap
systemctl restart fcgiwrap
```

`0660` — чтение/запись для владельца и группы.

### Способ 2 — запустить fcgiwrap от того же пользователя (рекомендован)

```bash
echo 'FCGIWRAP_USER=arris
FCGIWRAP_GROUP=arris' >> /etc/default/fcgiwrap
systemctl restart fcgiwrap
```

### Способ 3 — systemd override

```bash
systemctl edit fcgiwrap
```

```ini
[Service]
User=arris
Group=arris
```

```bash
systemctl daemon-reload && systemctl restart fcgiwrap
```

### Проверка

```bash
ls -la /run/fcgiwrap.socket
# srw-rw---- 1 arris arris 0 ...    — способ 2 или 3
# srw-rw---- 1 www-data www-data 0  — способ 1, nginx должен быть в группе www-data
```

---

## Ограничения

- git-http-backend **не поддерживает** push по HTTP без дополнительной настройки аутентификации. Для push используется SSH.
- Репозитории в GRASP — bare, пушить напрямую в storage не надо (только через зеркалирование).
- storage должен быть доступен на чтение пользователю nginx.

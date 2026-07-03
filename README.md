# GRASP — Git Repository Aggregation & Storage Platform

Mirror, track, and manage Git repositories from multiple hosting services.
Bare clones with scheduled auto-updates, REST API, and a vanilla JS SPA frontend.

## Features

- **Repository mirroring** — bare `git clone` + scheduled `git fetch --all --prune`
- **Multi-service** — GitHub, GitLab, Bitbucket, Codeberg, Gitea, SourceHut, self-hosted
- **Metadata enrichment** — auto-fetches description, language, topics, license from hosting API
- **Scheduled updates** — per-repo intervals (`1h`, `6h`, `1d`, `7d`, `30d`, `manual`, `never`)
- **Manual trigger** — force update any repo from UI or cron `--force`
- **State machine** — 11+ repo states (`pending_clone` → `cloning` → `pending_update` → ...) with event logging
- **Deferred deletion** — soft-delete with background cleanup
- **Service state** — start/stop/freeze controls (cron respects state)
- **Group & tag system** — organize repos by groups (with default intervals) and pipe-separated tags
- **Event audit log** — automatic event recording on every state change via DB triggers
- **REST API** — 21 endpoints for full CRUD + system operations
- **SPA frontend** — vanilla JS with day/night theme, toast notifications, responsive layout
- **Cron orchestration** — lock file, timeout, retry delay, max-per-run, run registry
- **URL parsers** — supports HTTPS, SSH, SCP-like, git:// — auto-detects service

## Requirements

- PHP 8.2+
- PHP extensions: `pdo`, `curl`, `redis` (declared, not yet used)
- SQLite
- Git (`/usr/bin/git`)
- Composer
- nginx + PHP-FPM (for web serving)

## Quick Start

```bash
# 1. Install dependencies
composer install --no-dev

# 2. Initialize database
php _setup.php

# 3. Optional: seed sample data
php _setup.php --seed

# 4. Configure web server (see debian/nginx.conf)
#    Point root to public/ and route /api/* to api.php
```

## Configuration

Config is loaded in this priority:

1. **`APP_CONFIG`** environment variable (passed via nginx `fastcgi_param`)
2. **`_config.php`** next to `api.php` (i.e. `<project_root>/_config.php`)

For CLI scripts (`cron.php`, `_setup.php`):

1. **`--config=/path/to/file`** CLI option
2. **`config.php`** in the script directory

Config files can be PHP (returning array) or YAML.

## API Endpoints

All routes are prefixed with `/api`. Responses are JSON.

| Method | Path | Description |
|---|---|---|
| GET | `/api/info` | API version info |
| **Repositories** | | |
| GET | `/api/repositories` | List (filters: group, tag, state, search) |
| POST | `/api/repositories` | Create (parses URL, auto-fetches description) |
| GET | `/api/repositories/{id}` | Get with recent events |
| PATCH | `/api/repositories/{id}` | Update metadata |
| DELETE | `/api/repositories/{id}` | Delete (immediate or deferred) |
| **Groups** | | |
| GET/POST | `/api/groups` | List / Create |
| GET/PATCH/DELETE | `/api/groups/{id}` | Get / Update / Delete |
| **Tags** | | |
| GET/POST | `/api/tags` | List / Create |
| DELETE | `/api/tags/{name}` | Delete |
| **Queue** | | |
| GET | `/api/queue/update` | List queue |
| POST | `/api/queue/update/trigger/{repo_id}` | Trigger clone/update |
| DELETE | `/api/queue/update/{repo_id}` | Cancel queue item |
| **Events** | | |
| GET | `/api/events` | List (filters: type, repo_id, limit) |
| GET | `/api/events/{id}` | Get single event |
| **System** | | |
| GET | `/api/system/status` | Full status + statistics |
| POST | `/api/system/status` | Change state (start/stop/freeze) |

## Cron

Run every minute:

```cron
* * * * * php /opt/grasp/cron.php >> /opt/grasp/logs/cron.log 2>&1
```

Options:

| Flag | Description |
|---|---|
| `--config=PATH` | Config file path (default: `./config.php`) |
| `--verbose` | Colored console output |
| `--force` | Force sync (highest priority item, bypasses schedule) |
| `--debug` | Show git command output |

The cron runner:

1. Acquires a lock file (stale after 300s)
2. Checks service state (exits if not `started`)
3. Records run in `cron_registry`
4. Processes deferred deletions (if enabled)
5. Schedules repos with expired `calculated_next_update`
6. Processes up to 3 queue items (or 1 in force mode)
7. Updates run registry with results

## Database

SQLite with 8 tables, 3 views, 6 triggers, 16 indexes.

Key tables: `repositories`, `groups`, `tags`, `update_queue`, `events`, `cron_registry`, `system_state`, `repo_remotes`.

Initialize with:

```bash
php _setup.php              # safe mode (IF NOT EXISTS)
php _setup.php --force      # drop & recreate
php _setup.php --seed       # + sample data
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

See `debian/nginx.conf` for a complete example.

## Frontend

Vanilla JS SPA with:

- Repository browser with group/tag/state/search filters
- Queue management with priority display
- Event log with type color-coding
- Group management table
- Day/night theme (respects `prefers-color-scheme`, stored in localStorage)
- Toast notifications
- URL hash routing
- Auto-refresh every 15s

## Development

```bash
make setup_env     # create storage/ and logs/ directories
composer install   # install PHP deps
php _setup.php --seed  # init DB with sample data
php cron.php --verbose  # run cron manually
```

## Packaging

```bash
make build         # dpkg-buildpackage → .deb → /srv/grasp/
```

## License

MIT

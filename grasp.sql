-- ============================================
-- GRASP Database Schema (SQLite)
-- ============================================

-- Включаем поддержку внешних ключей
PRAGMA foreign_keys = ON;

-- ============================================
-- Таблица: groups (Группы репозиториев)
-- ============================================
CREATE TABLE IF NOT EXISTS groups (
                                      id              INTEGER PRIMARY KEY AUTOINCREMENT,
                                      alias           TEXT    NOT NULL UNIQUE,          -- короткий алиас (например, "my-group")
                                      title           TEXT    NOT NULL,                 -- человекочитаемое название
                                      default_update_period TEXT NOT NULL DEFAULT '7d', -- период обновления по умолчанию (1h, 6h, 12h, 1d, 3d, 7d, 30d, manual, never)
                                      created_at      TEXT    NOT NULL DEFAULT (datetime('now')),
                                      updated_at      TEXT    NOT NULL DEFAULT (datetime('now'))
);

-- Индекс для поиска по алиасу
CREATE INDEX IF NOT EXISTS idx_groups_alias ON groups(alias);

-- ============================================
-- Таблица: repositories (Репозитории)
-- ============================================
CREATE TABLE IF NOT EXISTS repositories (
                                            id                  INTEGER PRIMARY KEY AUTOINCREMENT,
                                            repo_state          TEXT    NOT NULL DEFAULT 'need_clone',  -- need_clone, cloning, cloning_error, need_update, updating, updating_error, frozen, storage_error, error
                                            remote_url          TEXT    NOT NULL,                       -- полный URL удалённого репозитория
                                            user_name           TEXT    NOT NULL,                       -- владелец (извлекается из URL)
                                            repo_name           TEXT    NOT NULL,                       -- название репозитория (извлекается из URL)
                                            git_service         TEXT    NOT NULL,                       -- github, gitlab, bitbucket и т.д.
                                            storage_path        TEXT,                                   -- путь на диске: /storage/{service}/{user}/{repo}.git/
                                            description         TEXT    DEFAULT '',                     -- описание (извлекается из git, редактируемое)
                                            comment             TEXT    DEFAULT '',                     -- пользовательский комментарий
                                            repo_group          INTEGER,                                -- FK -> groups.id, NULL = общая группа
                                            tags                TEXT    DEFAULT '',                     -- теги, разделённые '|' (например, "tag1|tag2|tag3")
                                            update_interval     TEXT    NOT NULL DEFAULT '7d',          -- 1h, 6h, 12h, 1d, 3d, 7d, 30d, manual, never
                                            date_insert         TEXT    NOT NULL DEFAULT (datetime('now')),   -- дата добавления записи
                                            date_update         TEXT    NOT NULL DEFAULT (datetime('now')),   -- дата обновления мета-данных
                                            date_cloned_initial TEXT,                                         -- дата первого успешного клонирования
                                            date_cloned_last    TEXT,                                         -- дата последнего обновления (fetch)
                                            calculated_next_update TEXT,                                      -- date_cloned_last + update_interval

                                            FOREIGN KEY (repo_group) REFERENCES groups(id) ON DELETE SET NULL
);

-- Индексы для репозиториев
CREATE INDEX IF NOT EXISTS idx_repos_state      ON repositories(repo_state);
CREATE INDEX IF NOT EXISTS idx_repos_group      ON repositories(repo_group);
CREATE INDEX IF NOT EXISTS idx_repos_service    ON repositories(git_service);
CREATE INDEX IF NOT EXISTS idx_repos_user_repo  ON repositories(user_name, repo_name);
CREATE INDEX IF NOT EXISTS idx_repos_remote_url ON repositories(remote_url);
CREATE INDEX IF NOT EXISTS idx_repos_next_update ON repositories(calculated_next_update);

-- Уникальность: нельзя добавить один и тот же URL дважды
CREATE UNIQUE INDEX IF NOT EXISTS idx_repos_url_unique ON repositories(remote_url);

-- ============================================
-- Таблица: tags (Доступные теги)
-- ============================================
CREATE TABLE IF NOT EXISTS tags (
                                    id          INTEGER PRIMARY KEY AUTOINCREMENT,
                                    name        TEXT    NOT NULL UNIQUE,     -- имя тега
                                    created_at  TEXT    NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_tags_name ON tags(name);

-- ============================================
-- Таблица: update_queue (Очередь на клонирование/обновление)
-- ============================================
CREATE TABLE IF NOT EXISTS update_queue (
                                            id              INTEGER PRIMARY KEY AUTOINCREMENT,
                                            repo_id         INTEGER NOT NULL UNIQUE,        -- FK -> repositories.id
                                            queue_type      TEXT    NOT NULL DEFAULT 'clone', -- 'clone' или 'update'
                                            priority        INTEGER NOT NULL DEFAULT 0,      -- приоритет (0 = обычный, >0 = выше)
                                            scheduled_at    TEXT,                             -- запланированное время выполнения (NULL = сейчас)
                                            created_at      TEXT    NOT NULL DEFAULT (datetime('now')),
                                            attempts        INTEGER NOT NULL DEFAULT 0,      -- количество попыток
                                            last_attempt_at TEXT,                             -- время последней попытки

                                            FOREIGN KEY (repo_id) REFERENCES repositories(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_queue_repo_id   ON update_queue(repo_id);
CREATE INDEX IF NOT EXISTS idx_queue_type      ON update_queue(queue_type);
CREATE INDEX IF NOT EXISTS idx_queue_priority  ON update_queue(priority);
CREATE INDEX IF NOT EXISTS idx_queue_scheduled ON update_queue(scheduled_at);

-- ============================================
-- Таблица: events (События системы)
-- ============================================
CREATE TABLE IF NOT EXISTS events (
                                      id          INTEGER PRIMARY KEY AUTOINCREMENT,
                                      datetime    TEXT    NOT NULL DEFAULT (datetime('now')),  -- дата и время события
                                      event_type  TEXT    NOT NULL,                            -- тип события (need_clone, cloning, cloning_error, need_update, updating, updating_error, frozen, storage_error, error, stopped, started)
                                      repo_id     INTEGER,                                     -- FK -> repositories.id (NULL для системных событий)
                                      message     TEXT    DEFAULT '',                          -- краткое сообщение
                                      description TEXT    DEFAULT '',                          -- подробное описание (например, текст ошибки)

                                      FOREIGN KEY (repo_id) REFERENCES repositories(id) ON DELETE SET NULL
);

CREATE INDEX IF NOT EXISTS idx_events_datetime ON events(datetime DESC);
CREATE INDEX IF NOT EXISTS idx_events_type     ON events(event_type);
CREATE INDEX IF NOT EXISTS idx_events_repo_id  ON events(repo_id);

-- ============================================
-- Таблица: system_state (Текущее состояние сервиса)
-- ============================================
CREATE TABLE IF NOT EXISTS system_state (
                                            id              INTEGER PRIMARY KEY CHECK (id = 1), -- только одна строка
                                            service_state   TEXT    NOT NULL DEFAULT 'started',  -- started, stopped, frozen
                                            updated_at      TEXT    NOT NULL DEFAULT (datetime('now'))
);

-- Вставляем дефолтное состояние, если таблица пуста
INSERT OR IGNORE INTO system_state (id, service_state) VALUES (1, 'started');

-- ============================================
-- Таблица: cron_registry (Реестр запусков крона)
-- ============================================
CREATE TABLE IF NOT EXISTS cron_registry (
                                             id              INTEGER PRIMARY KEY AUTOINCREMENT,
                                             started_at      TEXT    NOT NULL DEFAULT (datetime('now')),
                                             finished_at     TEXT,
                                             status          TEXT    NOT NULL DEFAULT 'running',  -- running, completed, failed
                                             repos_processed INTEGER DEFAULT 0,                   -- сколько репозиториев обработано
                                             errors_count    INTEGER DEFAULT 0,                   -- количество ошибок
                                             log_output      TEXT    DEFAULT ''                   -- краткий лог выполнения
);

CREATE INDEX IF NOT EXISTS idx_cron_started ON cron_registry(started_at DESC);

-- ============================================
-- Таблица: repo_remotes (Дополнительные remote-ы репозитория)
-- для будущего расширения — возможность mirror-ить в несколько remote-ов
-- ============================================
CREATE TABLE IF NOT EXISTS repo_remotes (
                                            id          INTEGER PRIMARY KEY AUTOINCREMENT,
                                            repo_id     INTEGER NOT NULL,
                                            remote_name TEXT    NOT NULL DEFAULT 'origin',  -- имя remote (origin, upstream, mirror-1...)
                                            remote_url  TEXT    NOT NULL,                   -- URL
                                            is_mirror   INTEGER NOT NULL DEFAULT 0,         -- 1 = используется как зеркало для push

                                            FOREIGN KEY (repo_id) REFERENCES repositories(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_remotes_repo_id ON repo_remotes(repo_id);

-- ============================================
-- Представление: v_repositories (удобное для API)
-- ============================================
CREATE VIEW IF NOT EXISTS v_repositories AS
SELECT
    r.*,
    g.alias AS group_alias,
    g.title AS group_title,
    CASE
        WHEN q.id IS NOT NULL THEN 1
        ELSE 0
        END AS in_queue,
    q.queue_type,
    q.priority AS queue_priority,
    q.scheduled_at AS queue_scheduled_at
FROM repositories r
         LEFT JOIN groups g ON r.repo_group = g.id
         LEFT JOIN update_queue q ON r.id = q.repo_id;

-- ============================================
-- Представление: v_queue (очередь с данными репозиториев)
-- ============================================
CREATE VIEW IF NOT EXISTS v_queue AS
SELECT
    q.*,
    r.remote_url,
    r.user_name,
    r.repo_name,
    r.git_service,
    r.storage_path,
    r.repo_state,
    r.update_interval
FROM update_queue q
         JOIN repositories r ON q.repo_id = r.id
ORDER BY q.priority DESC, q.created_at ASC;

-- ============================================
-- Представление: v_events (события с именем репозитория)
-- ============================================
CREATE VIEW IF NOT EXISTS v_events AS
SELECT
    e.*,
    r.user_name || '/' || r.repo_name AS repo_full_name,
    r.remote_url AS repo_url
FROM events e
         LEFT JOIN repositories r ON e.repo_id = r.id;

-- ============================================
-- Триггер: автоматическое обновление updated_at в группах
-- ============================================
CREATE TRIGGER IF NOT EXISTS trg_groups_updated_at
    AFTER UPDATE ON groups
    FOR EACH ROW
BEGIN
    UPDATE groups SET updated_at = datetime('now') WHERE id = OLD.id;
END;

-- ============================================
-- Триггер: автоматическое обновление date_update в репозиториях
-- ============================================
CREATE TRIGGER IF NOT EXISTS trg_repos_updated_at
    AFTER UPDATE ON repositories
    FOR EACH ROW
BEGIN
    UPDATE repositories SET date_update = datetime('now') WHERE id = OLD.id;
END;

-- ============================================
-- Триггер: добавление события при смене состояния репозитория
-- ============================================
CREATE TRIGGER IF NOT EXISTS trg_repos_state_change
    AFTER UPDATE OF repo_state ON repositories
    FOR EACH ROW
    WHEN OLD.repo_state != NEW.repo_state
BEGIN
    INSERT INTO events (event_type, repo_id, message)
    VALUES (
               NEW.repo_state,
               NEW.id,
               'Состояние изменено: ' || OLD.repo_state || ' -> ' || NEW.repo_state
           );
END;

-- ============================================
-- Триггер: добавление события при добавлении репозитория
-- ============================================
CREATE TRIGGER IF NOT EXISTS trg_repos_insert
    AFTER INSERT ON repositories
    FOR EACH ROW
BEGIN
    INSERT INTO events (event_type, repo_id, message)
    VALUES ('need_clone', NEW.id, 'Репозиторий добавлен: ' || NEW.user_name || '/' || NEW.repo_name);
END;

-- ============================================
-- Триггер: автоматическое вычисление calculated_next_update
-- при изменении date_cloned_last или update_interval
-- ============================================
CREATE TRIGGER IF NOT EXISTS trg_repos_calc_next_update
    AFTER UPDATE OF date_cloned_last, update_interval ON repositories
    FOR EACH ROW
    WHEN NEW.date_cloned_last IS NOT NULL
BEGIN
    UPDATE repositories
    SET calculated_next_update = CASE
                                     WHEN NEW.update_interval = 'never' THEN NULL
                                     WHEN NEW.update_interval = 'manual' THEN NULL
                                     WHEN NEW.update_interval LIKE '%h' THEN
                                         datetime(NEW.date_cloned_last, '+' || REPLACE(NEW.update_interval, 'h', '') || ' hours')
                                     WHEN NEW.update_interval LIKE '%d' THEN
                                         datetime(NEW.date_cloned_last, '+' || REPLACE(NEW.update_interval, 'd', '') || ' days')
                                     ELSE NULL
        END
    WHERE id = NEW.id;
END;

-- ============================================
-- Триггер: отметка в events при изменении состояния сервиса
-- ============================================
CREATE TRIGGER IF NOT EXISTS trg_system_state_change
    AFTER UPDATE OF service_state ON system_state
    FOR EACH ROW
    WHEN OLD.service_state != NEW.service_state
BEGIN
    INSERT INTO events (event_type, message)
    VALUES (NEW.service_state, 'Состояние сервиса: ' || OLD.service_state || ' -> ' || NEW.service_state);

    UPDATE system_state SET updated_at = datetime('now') WHERE id = 1;
END;

-- ============================================
-- Начальные данные: дефолтные теги (опционально)
-- ============================================
-- INSERT OR IGNORE INTO tags (name) VALUES
--     ('production'),
--     ('development'),
--     ('archive'),
--     ('critical');
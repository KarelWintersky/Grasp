/**
 * GRASP Frontend Application
 */
class GraspApp {
    // Константы маппинга хэшей
    static HASH_TO_TAB = {
        '#repos':   'overview',
        '#queue':   'queue',
        '#events':  'events',
        '#groups':  'groups',
    };

    static TAB_TO_HASH = {
        'overview': '#repos',
        'queue':    '#queue',
        'events':   '#events',
        'groups':   '#groups',
    };

    constructor() {
        this.currentTab = 'overview';
        this.repos = [];
        this.groups = [];
        this.tags = [];
        this.queue = [];
        this.events = [];
        this.systemStatus = null;
        this.editingRepo = null;
        this.editingGroup = null;

        this.init();
    }

    init() {
        this.bindTabs();
        this.bindModals();
        this.bindForms();
        this.bindFilters();
        this.bindSystemControls();

        // Сначала определяем активную вкладку и показываем её
        this.initActiveTab();
    }

    async initActiveTab() {
        // Определяем вкладку из хэша или дефолтную
        const hash = window.location.hash;
        const activeTab = GraspApp.HASH_TO_TAB[hash] || 'overview';
        this.currentTab = activeTab;

        // Устанавливаем хэш если его нет
        if (!hash) {
            window.location.hash = GraspApp.TAB_TO_HASH[activeTab];
        }

        // Активируем нужную вкладку визуально
        document.querySelectorAll('.nav__tab').forEach(tab => {
            tab.classList.toggle('active', tab.dataset.tab === activeTab);
        });

        document.querySelectorAll('.tab-content').forEach(content => {
            content.classList.toggle('active', content.id === `tab-${activeTab}`);
        });

        // Загружаем данные для активной вкладки в первую очередь
        try {
            const al = await this.loadSystemStatus();
            this.applyAccessRestrictions(al);
        } catch (err) {
            this.applyAccessRestrictions('none');
        }

        if (activeTab === 'overview') {
            await this.loadGroups();
            await this.loadTags();
            await this.loadRepos(this.getCurrentFilters());
            await this.loadQueue();
        } else if (activeTab === 'queue') {
            await this.loadQueue();
        } else if (activeTab === 'events') {
            await this.loadGroups();
            await this.loadTags();
            await this.loadEvents();
        } else if (activeTab === 'groups') {
            await this.loadGroups();
            await this.loadRepos();
            this.renderGroupsTable(this._lastAccessLevel);
        }

        // Фоновая загрузка остальных данных
        this.loadRemainingData(activeTab);

        // Запускаем поллинг
        this.startPolling();
    }

    async loadRemainingData(activeTab) {
        try {
            const tasks = [];

            if (activeTab !== 'overview') {
                tasks.push(this.loadGroups());
                tasks.push(this.loadTags());
                tasks.push(this.loadRepos());
            }

            if (activeTab !== 'queue') {
                tasks.push(this.loadQueue());
            }

            if (activeTab !== 'events') {
                tasks.push(this.loadEvents());
            }

            if (activeTab !== 'groups' && activeTab !== 'overview') {
                // уже загружено в overview
            }

            if (tasks.length > 0) {
                await Promise.all(tasks);
            }
        } catch (err) {
            // Тихо — фоновая загрузка
        }
    }

    /**
     * Восстановить вкладку из URL-хэша
     */
    restoreTabFromHash() {
        const hash = window.location.hash;
        const tabName = GraspApp.HASH_TO_TAB[hash];

        if (tabName && tabName !== this.currentTab) {
            // Небольшая задержка, чтобы данные успели загрузиться
            setTimeout(() => {
                this.switchTab(tabName);
            }, 100);
        }
    }

    // === Data Loading ===
    async loadInitialData() {
        try {
            await Promise.all([
                this.loadSystemStatus(),
                this.loadGroups(),
                this.loadTags(),
                this.loadRepos(),
                this.loadQueue(),
            ]);
        } catch (err) {
            this.showToast('Ошибка загрузки данных: ' + err.message, 'error');
        }
    }

    async loadSystemStatus() {
        const { data, accessLevel } = await api.getSystemStatus();
        this.systemStatus = data;
        this._lastAccessLevel = accessLevel;
        this.renderSystemStatus();
        return accessLevel;
    }

    async loadGroups() {
        const { data, accessLevel } = await api.getGroups();
        this.groups = data;
        this._lastAccessLevel = accessLevel;
        this.renderGroupFilter();
        this.renderGroupSelects();
        return accessLevel;
    }

    async loadTags() {
        const { data, accessLevel } = await api.getTags();
        this.tags = data;
        this._lastAccessLevel = accessLevel;
        this.renderTagFilter();
        return accessLevel;
    }

    async loadRepos(filters = {}) {
        const { data, accessLevel } = await api.getRepositories(filters);
        this.repos = data;
        this._lastAccessLevel = accessLevel;
        this.renderRepos(accessLevel);
        this.renderStateFilter();
        this.updateStats();
        return accessLevel;
    }

    async loadQueue() {
        const { data, accessLevel } = await api.getUpdateQueue();
        this.queue = data;
        this._lastAccessLevel = accessLevel;
        this.renderQueue(accessLevel);
        this.updateQueueBadge();
        return accessLevel;
    }

    async loadEvents(filters = {}) {
        const { data, accessLevel } = await api.getEvents(filters);
        this.events = data;
        this._lastAccessLevel = accessLevel;
        this.renderEvents();
        this.renderEventTypeFilter();
        return accessLevel;
    }

    // === Polling ===
    startPolling() {
        setInterval(async () => {
            let al = this._lastAccessLevel;
            if (this.currentTab === 'overview') {
                await this.loadRepos(this.getCurrentFilters());
                await this.loadQueue();
            } else if (this.currentTab === 'queue') {
                await this.loadQueue();
            } else if (this.currentTab === 'events') {
                await this.loadEvents({ type: document.getElementById('eventTypeFilter')?.value || '' });
            } else if (this.currentTab === 'groups') {
                await this.loadGroups();
                await this.loadRepos();
                this.renderGroupsTable(this._lastAccessLevel);
            }
            al = await this.loadSystemStatus();
            this.applyAccessRestrictions(al);
        }, 15000);
    }

    // === Rendering ===
    renderSystemStatus() {
        const container = document.getElementById('serviceStatus');
        if (!this.systemStatus) return;

        const statusMap = {
            started: { class: 'status-indicator--started', text: 'Запущен' },
            frozen: { class: 'status-indicator--frozen', text: 'Заморожен' },
            stopped: { class: 'status-indicator--stopped', text: 'Остановлен' },
        };

        const status = statusMap[this.systemStatus.service_state] || statusMap.stopped;
        container.innerHTML = `
            <span class="status-indicator ${status.class}"></span>
            <span class="status-text">${status.text}</span>
        `;

        // Update buttons

        // Кнопки скрыты в версии 0.1.11+
        const btnFreeze = document.getElementById('btnFreeze');
        const btnStop = document.getElementById('btnStop');
        const btnStart = document.getElementById('btnStart');

        if (btnFreeze) {
            btnFreeze.style.display = this.systemStatus.service_state === 'frozen' ? 'none' : '';
        }

        if (btnStop) {
            btnStop.style.display = this.systemStatus.service_state === 'stopped' ? 'none' : '';
        }

        if (btnStart) {
            btnStart.style.display = this.systemStatus.service_state === 'started' ? 'none' : '';
        }
    }

    applyAccessRestrictions(accessLevel) {
        this._lastAccessLevel = accessLevel;

        if (accessLevel === 'none') {
            document.querySelector('.nav').style.display = 'none';
            document.querySelector('.main').style.display = 'none';
            const btnAbout = document.getElementById('btnAbout');
            if (btnAbout) btnAbout.click();
            return;
        }

        if (accessLevel === 'view') {
            const btnAddRepo = document.getElementById('btnAddRepo');
            if (btnAddRepo) btnAddRepo.style.display = 'none';

            const btnAddGroup = document.getElementById('btnAddGroupTab');
            if (btnAddGroup) btnAddGroup.style.display = 'none';
        }
    }

    renderRepos(accessLevel) {
        const container = document.getElementById('repoTree');
        if (this.repos.length === 0) {
            container.innerHTML = '<div class="loading">Нет репозиториев</div>';
            return;
        }

        // Group repos by group_id
        const grouped = {};
        for (const repo of this.repos) {
            const groupKey = repo.repo_group || '__ungrouped__';
            if (!grouped[groupKey]) {
                grouped[groupKey] = [];
            }
            grouped[groupKey].push(repo);
        }

        let html = '';
        for (const [groupKey, repos] of Object.entries(grouped)) {
            const groupName = groupKey === '__ungrouped__' ? 'Общая группа' : this.getGroupName(groupKey);
            const groupId = groupKey === '__ungrouped__' ? 'ungrouped' : groupKey;

            html += `
                <div class="repo-group">
                    <div class="repo-group__header" data-group="${groupId}">
                        <span class="arrow">▼</span>
                        <span>${this.escapeHtml(groupName)}</span>
                        <span class="repo-group__count">(${repos.length})</span>
                    </div>
                    <div class="repo-group__body">
                        ${repos.map(repo => this.renderRepoItem(repo, accessLevel)).join('')}
                    </div>
                </div>
            `;
        }

        container.innerHTML = html;

        // Bind group collapse
        container.querySelectorAll('.repo-group__header').forEach(header => {
            header.addEventListener('click', () => {
                header.classList.toggle('collapsed');
            });
        });

        // Bind repo click for details
        container.querySelectorAll('.repo-item').forEach(item => {
            item.addEventListener('click', (e) => {
                if (e.target.closest('.repo-item__actions')) return;
                this.showRepoDetails(item.dataset.repoId);
            });
        });

        // Bind action buttons
        container.querySelectorAll('[data-action]').forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.stopPropagation();
                const action = btn.dataset.action;
                const repoId = btn.dataset.repoId;
                if (action === 'edit') this.editRepo(repoId);
                if (action === 'delete') this.deleteRepo(repoId);
                if (action === 'trigger') this.triggerRepoUpdate(repoId);
            });
        });
    }

    renderRepoItem(repo, accessLevel) {
        const statusClass = `repo-item__status--${repo.repo_state}`;
        const tags = repo.tags ? repo.tags.split('|').filter(Boolean) : [];
        const isReadOnly = accessLevel === 'view';

        const actionsHtml = isReadOnly ? '' : `
            <button class="btn btn--sm" data-action="trigger" data-repo-id="${repo.id}" title="Обновить сейчас">↻</button>
            <button class="btn btn--sm" data-action="edit" data-repo-id="${repo.id}" title="Редактировать">✎</button>
            <button class="btn btn--sm btn--danger" data-action="delete" data-repo-id="${repo.id}" title="Удалить">✕</button>
        `;

        return `
            <div class="repo-item" data-repo-id="${repo.id}">
                <div class="repo-item__status ${statusClass}" title="${repo.repo_state}"></div>
                <div class="repo-item__info">
                    <div class="repo-item__name">${this.escapeHtml(repo.user_name)}/${this.escapeHtml(repo.repo_name)}</div>
                    <div class="repo-item__path">${this.escapeHtml(repo.storage_path || '—')}</div>
                </div>
                <div class="repo-item__description">${this.escapeHtml(repo.description || '')}</div>
                <div class="repo-item__tags">
                    ${tags.map(t => `<span class="repo-tag">${this.escapeHtml(t)}</span>`).join('')}
                </div>
                <div class="repo-item__interval">${this.escapeHtml(repo.update_interval)}</div>
                <div class="repo-item__actions">${actionsHtml}</div>
            </div>
        `;
    }

    renderQueue(accessLevel) {
        const container = document.getElementById('queueList');
        if (this.queue.length === 0) {
            container.innerHTML = '<div class="loading">Очередь пуста</div>';
            return;
        }

        const isReadOnly = accessLevel === 'view';
        container.innerHTML = this.queue.map((item, index) => `
            <div class="queue-item">
                <div class="queue-item__priority">#${index + 1}</div>
                <div class="queue-item__type queue-item__type--${item.queue_type}">
                    ${item.queue_type === 'clone' ? 'Клон' : 'Обнов'}
                </div>
                <div class="queue-item__name">${this.escapeHtml(item.repo_name)}</div>
                <div class="queue-item__scheduled">${item.scheduled_at || '—'}</div>
                ${isReadOnly ? '' : `<button class="btn btn--sm btn--danger" onclick="app.cancelQueue(${item.repo_id})">Отменить</button>`}
            </div>
        `).join('');
    }

    renderEvents() {
        const container = document.getElementById('eventsList');
        if (this.events.length === 0) {
            container.innerHTML = '<div class="loading">Нет событий</div>';
            return;
        }

        container.innerHTML = this.events.map(event => `
            <div class="event-item">
                <div class="event-item__time">${event.datetime}</div>
                <div class="event-item__type event-item__type--${event.event_type}">${event.event_type}</div>
                <div class="event-item__message">
                    ${this.escapeHtml(event.message || '')}
                    ${event.description ? `<div class="event-item__description">${this.escapeHtml(event.description)}</div>` : ''}
                </div>
            </div>
        `).join('');
    }

    renderGroupFilter() {
        const select = document.getElementById('filterGroup');
        select.innerHTML = '<option value="">Все группы</option>' +
            this.groups.map(g => `<option value="${g.id}">${this.escapeHtml(g.title)}</option>`).join('');
    }

    renderTagFilter() {
        const select = document.getElementById('filterTag');
        select.innerHTML = '<option value="">Все теги</option>' +
            this.tags.map(t => `<option value="${this.escapeHtml(t)}">${this.escapeHtml(t)}</option>`).join('');
    }

    renderStateFilter() {
        const select = document.getElementById('filterState');
        const states = [...new Set(this.repos.map(r => r.repo_state))];
        select.innerHTML = '<option value="">Все состояния</option>' +
            states.map(s => `<option value="${s}">${s}</option>`).join('');
    }

    renderEventTypeFilter() {
        const select = document.getElementById('eventTypeFilter');
        const types = [...new Set(this.events.map(e => e.event_type))];
        select.innerHTML = '<option value="">Все типы</option>' +
            types.map(t => `<option value="${t}">${t}</option>`).join('');
    }

    renderGroupSelects() {
        const options = this.groups.map(g =>
            `<option value="${g.id}" data-interval="${this.escapeHtml(g.default_update_period)}">${this.escapeHtml(g.title)}</option>`
        ).join('');

        const repoSelect = document.getElementById('repoGroup');
        repoSelect.innerHTML = '<option value="">Общая (без группы)</option>' + options;

        repoSelect.addEventListener('change', () => {
            const selected = repoSelect.selectedOptions[0];
            if (selected && selected.dataset.interval) {
                document.getElementById('updateInterval').value = selected.dataset.interval;
            }
        });
    }

    renderGroupsTable(accessLevel) {
        const tbody = document.getElementById('groupsTableBody');
        if (!tbody) return;

        /*if (this.groups.length === 0) {
            tbody.innerHTML = '<tr><td colspan="5" class="loading">Нет групп</td></tr>';
            return;
        }
        */

        // Подсчитываем количество репозиториев в каждой группе
        const repoCounts = {};
        for (const repo of this.repos) {
            const groupId = repo.repo_group || '__ungrouped__';
            repoCounts[groupId] = (repoCounts[groupId] || 0) + 1;
        }

        const ungroupedCount = repoCounts['__ungrouped__'] || 0;

        let html = '';

        // Сначала — Общая группа (системная, не удаляемая)
        html += `
        <tr class="groups-table__row--default">
            <td class="groups-table__alias">—</td>
            <td>Общая группа</td>
            <td class="groups-table__period">7d</td>
            <td class="groups-table__count">${ungroupedCount}</td>
            <td class="groups-table__actions">
                <span class="groups-table__hint">Системная</span>
            </td>
        </tr>
        `;

        // Затем — пользовательские группы
        const isReadOnly = accessLevel === 'view';
        html += this.groups.map(group => {
            const count = repoCounts[group.id] || 0;
            const actionsHtml = isReadOnly ? '' : `
                <button class="btn btn--sm" onclick="app.editGroup(${group.id})" title="Редактировать">✎</button>
                <button class="btn btn--sm btn--danger" onclick="app.deleteGroup(${group.id})" title="Удалить">✕</button>
            `;
            return `
            <tr>
                <td class="groups-table__alias">${this.escapeHtml(group.alias)}</td>
                <td>${this.escapeHtml(group.title)}</td>
                <td class="groups-table__period">${this.escapeHtml(group.default_update_period)}</td>
                <td class="groups-table__count">${count}</td>
                <td class="groups-table__actions">${actionsHtml}</td>
            </tr>
        `;
        }).join('');

        tbody.innerHTML = html;
    }

    renderRepoDetails(details, accessLevel) {
        const container = document.getElementById('detailsContent');
        const tags = details.tags ? details.tags.split('|').filter(Boolean) : [];

        container.innerHTML = `
        <div class="detail-grid">
            <div class="detail-label">URL</div>
            <div class="detail-value detail-value--mono">${this.escapeHtml(details.remote_url)}</div>

            <div class="detail-label">Сервис</div>
            <div class="detail-value">${this.escapeHtml(details.git_service)}</div>

            <div class="detail-label">Владелец</div>
            <div class="detail-value">${this.escapeHtml(details.user_name)}</div>

            <div class="detail-label">Репозиторий</div>
            <div class="detail-value detail-value--mono">${this.escapeHtml(details.repo_name)}</div>

            <div class="detail-label">Путь</div>
            <div class="detail-value detail-value--mono">${this.escapeHtml(details.storage_path)}</div>

            <div class="detail-label">Группа</div>
            <div class="detail-value">${details.repo_group ? this.getGroupName(details.repo_group) : 'Общая'}</div>

            <div class="detail-label">Интервал</div>
            <div class="detail-value">${this.escapeHtml(details.update_interval)}</div>

            <div class="detail-label">Состояние</div>
            <div class="detail-value">${this.escapeHtml(details.repo_state)}</div>

            <div class="detail-label">Описание</div>
            <div class="detail-value">${this.escapeHtml(details.description || '—')}</div>

            <div class="detail-label">Комментарий</div>
            <div class="detail-value">${this.escapeHtml(details.comment || '—')}</div>

            <div class="detail-label">Теги</div>
            <div class="detail-value">
                ${tags.length > 0 ? tags.map(t => `<span class="repo-tag">${this.escapeHtml(t)}</span>`).join(' ') : '—'}
            </div>

            <div class="detail-label">Добавлен</div>
            <div class="detail-value">${details.date_insert || '—'}</div>

            <div class="detail-label">Обновлён</div>
            <div class="detail-value">${details.date_update || '—'}</div>

            <div class="detail-label">Клонирован</div>
            <div class="detail-value">${details.date_cloned_initial || '—'}</div>

            <div class="detail-label">Последнее обновление</div>
            <div class="detail-value">${details.date_cloned_last || '—'}</div>

            <div class="detail-label">Следующее обновление</div>
            <div class="detail-value">${details.calculated_next_update || '—'}</div>
        </div>
        
        <!-- Кнопки с data-атрибутами для идентификации -->
        <div class="form-actions">
            <button class="btn" id="btnDetailsClose">Закрыть</button>
            ${accessLevel === 'view' ? '' : `
                <button class="btn btn--primary" id="btnDetailsEdit">Редактировать</button>
                <button class="btn" id="btnDetailsTrigger">Обновить сейчас</button>
            `}
        </div>
    `;
    }
    updateStats() {
        const container = document.getElementById('statsOverview');
        if (!container) return;
        const total = this.repos.length;
        const errors = this.repos.filter(r =>
            r.repo_state.includes('error')
        ).length;
        container.innerHTML = `Всего: ${total} | С ошибками: ${errors}`;
    }

    updateQueueBadge() {
        const badge = document.getElementById('queueBadge');
        if (!badge) return;

        const count = this.queue.length;
        badge.textContent = count;

        // Если 0 — делаем бейдж серым, если >0 — оставляем цвет primary
        if (count === 0) {
            badge.classList.add('nav__badge--empty');
        } else {
            badge.classList.remove('nav__badge--empty');
        }
    }

    getGroupName(groupId) {
        const group = this.groups.find(g => g.id == groupId);
        return group ? group.title : `Группа #${groupId}`;
    }

    getCurrentFilters() {
        return {
            group: document.getElementById('filterGroup').value,
            tag: document.getElementById('filterTag').value,
            state: document.getElementById('filterState').value,
            search: document.getElementById('filterSearch').value,
        };
    }

    // === Actions ===
    async addRepo() {
        this.editingRepo = null;
        document.getElementById('modalRepoTitle').textContent = 'Добавить репозиторий';
        document.getElementById('formRepo').reset();
        document.getElementById('repoId').value = '';
        document.getElementById('updateInterval').value = '7d';
        this.openModal('modalRepo');
        document.getElementById('remoteUrl').focus();
    }

    async editRepo(repoId) {
        this.editingRepo = repoId;
        document.getElementById('modalRepoTitle').textContent = 'Редактировать репозиторий';

        const repo = this.repos.find(r => r.id == repoId) || await api.getRepository(repoId);

        document.getElementById('repoId').value = repo.id;
        document.getElementById('remoteUrl').value = repo.remote_url;
        document.getElementById('repoGroup').value = repo.repo_group || '';
        document.getElementById('updateInterval').value = repo.update_interval || '7d';
        document.getElementById('repoDescription').value = repo.description || '';
        document.getElementById('repoComment').value = repo.comment || '';
        document.getElementById('repoTags').value = repo.tags || '';

        this.openModal('modalRepo');
    }

    async deleteRepo(repoId) {
        const repo = this.repos.find(r => r.id == repoId);
        const repoName = repo ? `${repo.user_name}/${repo.repo_name}` : `#${repoId}`;

        const confirmed = confirm(
            `Вы уверены, что хотите удалить репозиторий?\n\n${repoName}\n\nРепозиторий будет удалён из базы данных и с диска. Это действие нельзя отменить.`
        );
        // 'Вы уверены, что хотите удалить этот репозиторий? Это действие нельзя отменить.'

        if (!confirmed) return;

        try {
            await api.deleteRepository(repoId);
            this.showToast('Репозиторий удален', 'success');
            await this.loadRepos(this.getCurrentFilters());
            await this.loadQueue();
        } catch (err) {
            this.showToast('Ошибка удаления: ' + err.message, 'error');
        }
    }

    async triggerRepoUpdate(repoId) {
        try {
            await api.triggerUpdate(repoId);
            this.showToast('Репозиторий поставлен в очередь на обновление', 'success');
            await this.loadQueue();
        } catch (err) {
            this.showToast('Ошибка: ' + err.message, 'error');
        }
    }

    async cancelQueue(repoId) {
        try {
            await api.cancelQueueItem(repoId);
            this.showToast('Задача удалена из очереди', 'info');
            await this.loadQueue();
        } catch (err) {
            this.showToast('Ошибка: ' + err.message, 'error');
        }
    }

    async saveRepo(e) {
        e.preventDefault();

        const data = {
            remote_url: document.getElementById('remoteUrl').value,
            repo_group: document.getElementById('repoGroup').value || null,
            update_interval: document.getElementById('updateInterval').value,
            description: document.getElementById('repoDescription').value,
            comment: document.getElementById('repoComment').value,
            tags: document.getElementById('repoTags').value,
        };

        try {
            if (this.editingRepo) {
                await api.updateRepository(this.editingRepo, data);
                this.showToast('Репозиторий обновлен', 'success');
            } else {
                await api.createRepository(data);
                this.showToast('Репозиторий добавлен', 'success');
            }

            this.closeModal('modalRepo');
            await this.loadRepos(this.getCurrentFilters());
            await this.loadQueue();
        } catch (err) {
            this.showToast('Ошибка сохранения: ' + err.message, 'error');
        }
    }

    async addGroup() {
        this.editingGroup = null;
        document.getElementById('modalGroupTitle').textContent = 'Новая группа';
        document.getElementById('formGroup').reset();
        document.getElementById('groupId').value = '';
        document.getElementById('groupUpdatePeriod').value = '7d';
        this.openModal('modalGroup');
        document.getElementById('groupAlias').focus();
    }

    async editGroup(groupId) {
        this.editingGroup = groupId;
        document.getElementById('modalGroupTitle').textContent = 'Редактировать группу';

        const group = this.groups.find(g => g.id == groupId) || await api.getGroup(groupId);

        document.getElementById('groupId').value = group.id;
        document.getElementById('groupAlias').value = group.alias;
        document.getElementById('groupTitle').value = group.title;
        document.getElementById('groupUpdatePeriod').value = group.default_update_period;

        this.openModal('modalGroup');
    }

    async deleteGroup(groupId) {
        const group = this.groups.find(g => g.id == groupId);
        if (!group) {
            this.showToast('Группа не найдена', 'error');
            return;
        }

        const confirmed = confirm(
            `Вы уверены, что хотите удалить группу «${group.title}»?\n\nВсе репозитории из этой группы перейдут в «Общую группу».`
        );

        if (!confirmed) return;

        try {
            await api.deleteGroup(groupId);
            this.showToast('Группа удалена', 'success');

            // Перезагружаем группы и репозитории
            await this.loadGroups();
            await this.loadRepos(this.getCurrentFilters());
            // Если мы на вкладке групп — обновим таблицу
            if (this.currentTab === 'groups') {
                this.renderGroupsTable(this._lastAccessLevel);
            }

        } catch (err) {
            this.showToast('Ошибка удаления группы: ' + err.message, 'error');
        }
    }

    async saveGroup(e) {
        e.preventDefault();

        const data = {
            alias: document.getElementById('groupAlias').value,
            title: document.getElementById('groupTitle').value,
            default_update_period: document.getElementById('groupUpdatePeriod').value,
        };

        try {
            if (this.editingGroup) {
                await api.updateGroup(this.editingGroup, data);
                this.showToast('Группа обновлена', 'success');
            } else {
                await api.createGroup(data);
                this.showToast('Группа создана', 'success');
            }

            this.closeModal('modalGroup');

            // Перезагружаем данные
            await this.loadGroups();
            await this.loadRepos(this.getCurrentFilters());

            // Если мы на вкладке групп — обновляем таблицу
            if (this.currentTab === 'groups') {
                this.renderGroupsTable(this._lastAccessLevel);
            }

        } catch (err) {
            this.showToast('Ошибка сохранения: ' + err.message, 'error');
        }
    }

    async setSystemState(action) {
        try {
            await api.setSystemStatus(action);
            this.showToast(`Сервис ${action === 'start' ? 'запущен' : action === 'stop' ? 'остановлен' : 'заморожен'}`, 'info');
            await this.loadSystemStatus();
        } catch (err) {
            this.showToast('Ошибка: ' + err.message, 'error');
        }
    }

    async showRepoDetails(repoId) {
        try {
            const { data, accessLevel } = await api.getRepository(repoId);
            document.getElementById('modalDetailsTitle').textContent =
                `Детали: ${data.user_name}/${data.repo_name}`;
            this.renderRepoDetails(data, accessLevel);

            // Сначала открываем модалку
            this.openModal('modalDetails');

            // Затем навешиваем обработчики на кнопки (они уже в DOM)
            this.bindDetailsButtons(details);

        } catch (err) {
            this.showToast('Ошибка загрузки деталей: ' + err.message, 'error');
        }
    }

    // === UI Helpers ===
    openModal(modalId) {
        document.getElementById(modalId).classList.add('active');
    }

    closeModal(modalId) {
        document.getElementById(modalId).classList.remove('active');
    }

    showToast(message, type = 'info') {
        const container = document.getElementById('toastContainer');
        const toast = document.createElement('div');
        toast.className = `toast toast--${type}`;
        toast.textContent = message;
        container.appendChild(toast);

        setTimeout(() => {
            toast.remove();
        }, 4000);
    }

    switchTab(tabName) {
        if (this.currentTab === tabName) return;

        this.currentTab = tabName;

        // Update URL hash
        window.location.hash = GraspApp.TAB_TO_HASH[tabName] || '';

        // Update nav tabs
        document.querySelectorAll('.nav__tab').forEach(tab => {
            tab.classList.toggle('active', tab.dataset.tab === tabName);
        });

        // Update content
        document.querySelectorAll('.tab-content').forEach(content => {
            content.classList.toggle('active', content.id === `tab-${tabName}`);
        });

        // Load tab data
        if (tabName === 'events') {
            this.loadEvents({ type: document.getElementById('eventTypeFilter')?.value || '' });
        } else if (tabName === 'queue') {
            this.loadQueue();
        } else if (tabName === 'overview') {
            this.loadRepos(this.getCurrentFilters());
        } else if (tabName === 'groups') {
            // Группы уже загружены в this.groups — просто рендерим
            if (this.groups.length > 0) {
                this.renderGroupsTable(this._lastAccessLevel);
            } else {
                // На всякий случай подгрузим
                this.loadGroups().then(al => this.renderGroupsTable(al));
            }
        }
    }

    escapeHtml(str) {
        if (!str) return '';
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    // === Event Binding ===
    bindTabs() {
        document.querySelectorAll('.nav__tab').forEach(tab => {
            tab.addEventListener('click', () => {
                this.switchTab(tab.dataset.tab);
            });
        });

        // Слушаем кнопки назад/вперёд браузера
        window.addEventListener('hashchange', () => {
            this.restoreTabFromHash();
        });
    }

    bindModals() {
        // Close buttons
        document.querySelectorAll('[data-close]').forEach(btn => {
            btn.addEventListener('click', () => {
                this.closeModal(btn.dataset.close);
            });
        });

        // Overlay clicks
        document.querySelectorAll('.modal__overlay').forEach(overlay => {
            overlay.addEventListener('click', () => {
                const modal = overlay.closest('.modal');
                if (modal) this.closeModal(modal.id);
            });
        });

        // Escape key
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                document.querySelectorAll('.modal.active').forEach(modal => {
                    this.closeModal(modal.id);
                });
            }
        });
    }

    /**
     *
     */
    bindForms() {
        // Form submit handlers — с защитой от null
        const formRepo = document.getElementById('formRepo');
        if (formRepo) {
            formRepo.addEventListener('submit', (e) => this.saveRepo(e));
        }

        const formGroup = document.getElementById('formGroup');
        if (formGroup) {
            formGroup.addEventListener('submit', (e) => this.saveGroup(e));
        }

        // Add buttons
        const btnAddRepo = document.getElementById('btnAddRepo');
        if (btnAddRepo) {
            btnAddRepo.addEventListener('click', () => this.addRepo());
        }

        const btnAddGroupTab = document.getElementById('btnAddGroupTab');
        if (btnAddGroupTab) {
            btnAddGroupTab.addEventListener('click', () => this.addGroup());
        }

        // Interval presets
        document.querySelectorAll('.preset-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                const intervalInput = btn.closest('.interval-input')?.querySelector('input');
                if (intervalInput) {
                    intervalInput.value = btn.dataset.interval;
                    btn.parentElement.querySelectorAll('.preset-btn').forEach(b => b.classList.remove('active'));
                    btn.classList.add('active');
                }
            });
        });
    }

    bindFilters() {
        const filterGroup = document.getElementById('filterGroup');
        const filterTag = document.getElementById('filterTag');
        const filterState = document.getElementById('filterState');
        const filterSearch = document.getElementById('filterSearch');
        const eventTypeFilter = document.getElementById('eventTypeFilter');

        const handler = () => this.loadRepos(this.getCurrentFilters());

        if (filterGroup) filterGroup.addEventListener('change', handler);
        if (filterTag) filterTag.addEventListener('change', handler);
        if (filterState) filterState.addEventListener('change', handler);

        if (filterSearch) {
            let searchTimeout;
            filterSearch.addEventListener('input', () => {
                clearTimeout(searchTimeout);
                searchTimeout = setTimeout(() => this.loadRepos(this.getCurrentFilters()), 300);
            });
        }

        if (eventTypeFilter) {
            eventTypeFilter.addEventListener('change', () => {
                this.loadEvents({ type: eventTypeFilter.value });
            });
        }

        const btnRefreshQueue = document.getElementById('btnRefreshQueue');
        if (btnRefreshQueue) btnRefreshQueue.addEventListener('click', () => this.loadQueue());

        const btnRefreshEvents = document.getElementById('btnRefreshEvents');
        if (btnRefreshEvents) btnRefreshEvents.addEventListener('click', () => {
            this.loadEvents({ type: eventTypeFilter?.value || '' });
        });
    }

    bindSystemControls() {
        const btnFreeze = document.getElementById('btnFreeze');
        const btnStop = document.getElementById('btnStop');
        const btnStart = document.getElementById('btnStart');

        if (btnFreeze) btnFreeze.addEventListener('click', () => this.setSystemState('freeze'));
        if (btnStop) btnStop.addEventListener('click', () => this.setSystemState('stop'));
        if (btnStart) btnStart.addEventListener('click', () => this.setSystemState('start'));

        const btnAbout = document.getElementById('btnAbout');
        if (btnAbout) {
            btnAbout.addEventListener('click', () => this.openModal('modalAbout'));
        }
    }

    /* **
    * Bind event handlers for the details modal buttons.
    * Called AFTER the modal is opened (so buttons are in DOM).
    */
    bindDetailsButtons(details) {
        // Закрыть
        const btnClose = document.getElementById('btnDetailsClose');
        if (btnClose) {
            // Удаляем старый обработчик (если был), вешаем новый
            const newBtnClose = btnClose.cloneNode(true);
            btnClose.parentNode.replaceChild(newBtnClose, btnClose);
            newBtnClose.addEventListener('click', () => {
                this.closeModal('modalDetails');
            });
        }

        // Редактировать
        const btnEdit = document.getElementById('btnDetailsEdit');
        if (btnEdit) {
            const newBtnEdit = btnEdit.cloneNode(true);
            btnEdit.parentNode.replaceChild(newBtnEdit, btnEdit);
            newBtnEdit.addEventListener('click', () => {
                this.closeModal('modalDetails');
                this.editRepo(details.id);
            });
        }

        // Обновить сейчас
        const btnTrigger = document.getElementById('btnDetailsTrigger');
        if (btnTrigger) {
            const newBtnTrigger = btnTrigger.cloneNode(true);
            btnTrigger.parentNode.replaceChild(newBtnTrigger, btnTrigger);
            newBtnTrigger.addEventListener('click', () => {
                this.closeModal('modalDetails');
                this.triggerRepoUpdate(details.id);
            });
        }
    }
}

// Initialize app

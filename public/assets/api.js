/**
 * GRASP API Client
 *
 * Base URL: /api (nginx proxies to api.php)
 */
class GraspAPI {
    constructor(baseURL = '/api') {
        this.baseURL = baseURL;
    }

    /**
     * Make an API request
     */
    async request(method, endpoint, data = null) {
        let url = `${this.baseURL}${endpoint}`;
        const options = {
            method,
            headers: {
                'Accept': 'application/json',
            },
        };

        // Body for non-GET requests
        if (data && method !== 'GET') {
            options.headers['Content-Type'] = 'application/json';
            options.body = JSON.stringify(data);
        }

        // Query string for GET requests
        if (data && method === 'GET') {
            const params = new URLSearchParams();
            for (const [key, value] of Object.entries(data)) {
                if (value !== null && value !== undefined && value !== '') {
                    params.append(key, value);
                }
            }
            const qs = params.toString();
            if (qs) url += '?' + qs;
        }

        const response = await fetch(url, options);
        const json = await response.json();

        if (json.status === 'error') {
            throw new Error(json.message || 'Unknown API error');
        }

        return { data: json.data, accessLevel: json.access_level || 'admin' };
    }

    // ==========================================
    // Repositories
    // ==========================================
    getRepositories(filters = {}) {
        return this.request('GET', '/repositories', filters);
    }

    getRepository(id) {
        return this.request('GET', `/repositories/${id}`);
    }

    createRepository(data) {
        return this.request('POST', '/repositories', data);
    }

    updateRepository(id, data) {
        return this.request('PATCH', `/repositories/${id}`, data);
    }

    deleteRepository(id) {
        return this.request('DELETE', `/repositories/${id}`);
    }

    // ==========================================
    // Groups
    // ==========================================
    getGroups() {
        return this.request('GET', '/groups');
    }

    getGroup(id) {
        return this.request('GET', `/groups/${id}`);
    }

    createGroup(data) {
        return this.request('POST', '/groups', data);
    }

    updateGroup(id, data) {
        return this.request('PATCH', `/groups/${id}`, data);
    }

    deleteGroup(id) {
        return this.request('DELETE', `/groups/${id}`);
    }

    // ==========================================
    // Tags
    // ==========================================
    getTags() {
        return this.request('GET', '/tags');
    }

    createTag(name) {
        return this.request('POST', '/tags', { name });
    }

    deleteTag(name) {
        return this.request('DELETE', `/tags/${encodeURIComponent(name)}`);
    }

    // ==========================================
    // Queue
    // ==========================================
    getUpdateQueue() {
        return this.request('GET', '/queue/update');
    }

    triggerUpdate(repoId) {
        return this.request('POST', `/queue/update/trigger/${repoId}`);
    }

    cancelQueueItem(repoId) {
        return this.request('DELETE', `/queue/update/${repoId}`);
    }

    // ==========================================
    // Events
    // ==========================================
    getEvents(filters = {}) {
        return this.request('GET', '/events', filters);
    }

    getEvent(id) {
        return this.request('GET', `/events/${id}`);
    }

    // ==========================================
    // System
    // ==========================================
    getSystemStatus() {
        return this.request('GET', '/system/status');
    }

    setSystemStatus(action) {
        return this.request('POST', '/system/status', { action });
    }
}

// Singleton
const api = new GraspAPI();
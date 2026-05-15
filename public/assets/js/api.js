/**
 * GRASP API Client
 */
class GraspAPI {
    constructor(baseURL = '/api.php') {
        this.baseURL = baseURL;
    }

    async request(method, endpoint, data = null) {
        const url = `${this.baseURL}${endpoint}`;
        const options = {
            method,
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
            },
        };

        if (data && method !== 'GET') {
            options.body = JSON.stringify(data);
        }

        let queryString = '';
        if (data && method === 'GET') {
            const params = new URLSearchParams();
            for (const [key, value] of Object.entries(data)) {
                if (value !== null && value !== undefined && value !== '') {
                    params.append(key, value);
                }
            }
            queryString = params.toString();
            if (queryString) {
                queryString = '?' + queryString;
            }
        }

        const response = await fetch(url + queryString, options);
        const json = await response.json();

        if (json.status === 'error') {
            throw new Error(json.message || 'Unknown API error');
        }

        return json.data;
    }

    // Repositories
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

    // Groups
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

    // Tags
    getTags() {
        return this.request('GET', '/tags');
    }

    createTag(name) {
        return this.request('POST', '/tags', { name });
    }

    deleteTag(name) {
        return this.request('DELETE', `/tags/${name}`);
    }

    // Queue
    getUpdateQueue() {
        return this.request('GET', '/queue/update');
    }

    triggerUpdate(repoId) {
        return this.request('POST', `/queue/update/trigger/${repoId}`);
    }

    cancelQueueItem(repoId) {
        return this.request('DELETE', `/queue/update/${repoId}`);
    }

    // Events
    getEvents(filters = {}) {
        return this.request('GET', '/events', filters);
    }

    getEvent(id) {
        return this.request('GET', `/events/${id}`);
    }

    // System
    getSystemStatus() {
        return this.request('GET', '/system/status');
    }

    setSystemStatus(action) {
        return this.request('POST', '/system/status', { action });
    }
}

// Singleton
const api = new GraspAPI();
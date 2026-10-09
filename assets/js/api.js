/**
 * MilkBook - API Wrapper
 * Handles fetch calls, JSON payloads, response envelopes, and error handling.
 */

const api = {
    csrfToken: sessionStorage.getItem('milkbook_csrf_token') || '',

    /**
     * Set active CSRF token
     */
    setCsrfToken(token) {
        this.csrfToken = token;
        if (token) {
            sessionStorage.setItem('milkbook_csrf_token', token);
        } else {
            sessionStorage.removeItem('milkbook_csrf_token');
        }
    },

    /**
     * Check auth status & fetch CSRF token on startup
     */
    async initAuth() {
        try {
            const res = await fetch('api/auth.php?action=status');
            if (res.ok) {
                const json = await res.json();
                if (json.success && json.data) {
                    if (json.data.csrf_token) {
                        this.setCsrfToken(json.data.csrf_token);
                    }
                    if (json.data.auth_enabled && !json.data.is_logged_in) {
                        if (!window.location.pathname.endsWith('login.html')) {
                            window.location.href = 'login.html';
                        }
                    }
                }
            }
        } catch (e) {
            // Ignore offline/local failure during startup
        }
    },

    /**
     * Core request handler
     *
     * @param {string} url
     * @param {object} options
     * @returns {Promise<any>}
     */
    async request(url, options = {}) {
        const defaultHeaders = {
            'Accept': 'application/json',
            'Content-Type': 'application/json'
        };

        if (this.csrfToken) {
            defaultHeaders['X-CSRF-Token'] = this.csrfToken;
        }

        const config = {
            ...options,
            headers: {
                ...defaultHeaders,
                ...(options.headers || {})
            }
        };

        try {
            const response = await fetch(url, config);
            let json = null;

            // Handle 401 Unauthorized
            if (response.status === 401) {
                if (!window.location.pathname.endsWith('login.html')) {
                    window.location.href = 'login.html';
                }
            }

            // Attempt to parse JSON response
            const contentType = response.headers.get('content-type') || '';
            if (contentType.includes('application/json')) {
                json = await response.json();
            } else {
                const text = await response.text();
                throw new Error(`Server returned unexpected response (${response.status}): ${text.substring(0, 100)}`);
            }

            // Envelope check: { success: true/false, data: ..., error: ... }
            if (!json.success) {
                const errorMessage = json.error?.message || 'An unexpected error occurred.';
                const errorFields = json.error?.fields || {};
                
                const err = new Error(errorMessage);
                err.fields = errorFields;
                err.status = response.status;
                err.raw = json;

                // Show toast notification if UI helper is available and not suppressed
                if (!options.silent && window.ui && typeof window.ui.toast === 'function') {
                    let fullMsg = errorMessage;
                    if (Object.keys(errorFields).length > 0) {
                        const fieldDetails = Object.values(errorFields).join(' ');
                        fullMsg += ` (${fieldDetails})`;
                    }
                    window.ui.toast(fullMsg, 'danger', 'Error');
                }

                throw err;
            }

            return json.data;
        } catch (error) {
            // Network failures or CORS issues
            if (!error.status && !options.silent && window.ui && typeof window.ui.toast === 'function') {
                window.ui.toast(error.message || 'Network error. Please check your connection.', 'danger', 'Network Error');
            }
            throw error;
        }
    },

    /**
     * HTTP GET
     */
    async get(url, options = {}) {
        return this.request(url, { ...options, method: 'GET' });
    },

    /**
     * HTTP POST
     */
    async post(url, data, options = {}) {
        return this.request(url, {
            ...options,
            method: 'POST',
            body: JSON.stringify(data)
        });
    },

    /**
     * HTTP PUT
     */
    async put(url, data, options = {}) {
        return this.request(url, {
            ...options,
            method: 'PUT',
            body: JSON.stringify(data)
        });
    },

    /**
     * HTTP DELETE
     */
    async delete(url, data = null, options = {}) {
        const config = { ...options, method: 'DELETE' };
        if (data) {
            config.body = JSON.stringify(data);
        }
        return this.request(url, config);
    }
};

window.api = api;

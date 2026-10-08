/*
 * Muninn API client.
 *
 * Every call goes to the API with the session cookie (credentials: "include"). State-changing
 * calls carry the per-session CSRF token in the X-CSRF-Token header. The token is kept in memory
 * only; after a page load it is fetched again from GET /auth/me when first needed.
 */
(function () {
    'use strict';

    /** Reads a value the PHP page placed in a <meta> tag. */
    function readMeta(metaName) {
        var metaElement = document.querySelector('meta[name="' + metaName + '"]');
        return metaElement ? metaElement.getAttribute('content') : '';
    }

    var apiBaseUrl = readMeta('muninn-api-base').replace(/\/+$/, '');
    var siteRoot = readMeta('muninn-site-root') || './';
    var csrfToken = null;

    /** An API error carrying the HTTP status, error code, message and per-field messages. */
    function ApiError(status, code, message, fields) {
        this.name = 'ApiError';
        this.status = status;
        this.code = code;
        this.message = message;
        this.fields = fields || {};
    }
    ApiError.prototype = Object.create(Error.prototype);

    /**
     * Sends a request and resolves with the "data" part of the response (or null for 204).
     * Rejects with an ApiError for any non-2xx answer or network failure.
     */
    async function request(method, path, body) {
        var isStateChanging = method !== 'GET' && method !== 'HEAD';
        var requestHeaders = { 'Accept': 'application/json' };

        if (isStateChanging && csrfToken === null && !isPublicPath(path)) {
            await loadCurrentUser();
        }
        if (isStateChanging && csrfToken !== null) {
            requestHeaders['X-CSRF-Token'] = csrfToken;
        }
        if (body !== undefined) {
            requestHeaders['Content-Type'] = 'application/json';
        }

        var response;
        try {
            response = await fetch(apiBaseUrl + path, {
                method: method,
                credentials: 'include',
                headers: requestHeaders,
                body: body === undefined ? undefined : JSON.stringify(body),
            });
        } catch (networkError) {
            throw new ApiError(0, 'network_error', 'Cannot reach Muninn. Check your connection and try again.');
        }

        if (response.status === 204) {
            return null;
        }

        var responseJson = null;
        try {
            responseJson = await response.json();
        } catch (parseError) {
            responseJson = null;
        }

        if (!response.ok) {
            var errorBody = responseJson && responseJson.error ? responseJson.error : {};
            throw new ApiError(
                response.status,
                errorBody.code || 'unknown_error',
                errorBody.message || 'Something went wrong. Please try again.',
                errorBody.fields
            );
        }

        var responseData = responseJson ? responseJson.data : null;
        // Login, /auth/me and invitation acceptance all hand out a fresh CSRF token.
        if (responseData && typeof responseData.csrf_token === 'string') {
            csrfToken = responseData.csrf_token;
        }
        return responseData;
    }

    /**
     * Uploads a file (an image) as the raw request body and resolves with the "data" part.
     * The API detects the real file type itself; the filename is display text only.
     */
    async function uploadFile(path, fileBlob, fileName) {
        if (csrfToken === null) {
            await loadCurrentUser();
        }
        var requestHeaders = {
            'Accept': 'application/json',
            'X-CSRF-Token': csrfToken,
            'Content-Type': fileBlob.type || 'application/octet-stream',
        };
        if (fileName) {
            requestHeaders['X-Filename'] = encodeURIComponent(fileName);
        }

        var response;
        try {
            response = await fetch(apiBaseUrl + path, {
                method: 'POST',
                credentials: 'include',
                headers: requestHeaders,
                body: fileBlob,
            });
        } catch (networkError) {
            throw new ApiError(0, 'network_error', 'Cannot reach Muninn. Check your connection and try again.');
        }

        var responseJson = null;
        try {
            responseJson = await response.json();
        } catch (parseError) {
            responseJson = null;
        }
        if (!response.ok) {
            var errorBody = responseJson && responseJson.error ? responseJson.error : {};
            throw new ApiError(
                response.status,
                errorBody.code || 'unknown_error',
                errorBody.message || 'The upload failed. Please try again.',
                errorBody.fields
            );
        }
        return responseJson ? responseJson.data : null;
    }

    /** Endpoints that work without a session, so no CSRF token is needed first. */
    function isPublicPath(path) {
        return path === '/api/v1/auth/login' || path.indexOf('/api/v1/invitations/') === 0;
    }

    /** Returns {user, csrf_token} for the signed-in user; rejects with status 401 otherwise. */
    function loadCurrentUser() {
        return request('GET', '/api/v1/auth/me');
    }

    /** Navigates to a page relative to the site root (works from sub-folders such as admin/). */
    function goTo(pageName) {
        window.location.assign(siteRoot + pageName);
    }

    /** Signs out on the server, forgets the CSRF token and returns to the sign-in page. */
    async function signOut() {
        try {
            await request('POST', '/api/v1/auth/logout');
        } finally {
            csrfToken = null;
            goTo('login.php');
        }
    }

    /**
     * Shows API field errors on a form: marks inputs invalid and fills their feedback element.
     * fieldInputIds maps API field names to input element IDs.
     */
    function showFieldErrors(fieldInputIds, fieldErrors) {
        Object.keys(fieldInputIds).forEach(function (fieldName) {
            var inputElement = document.getElementById(fieldInputIds[fieldName]);
            var feedbackElement = document.getElementById(fieldInputIds[fieldName] + '-feedback');
            var fieldMessage = fieldErrors[fieldName];
            if (!inputElement) {
                return;
            }
            inputElement.classList.toggle('is-invalid', Boolean(fieldMessage));
            inputElement.setAttribute('aria-invalid', fieldMessage ? 'true' : 'false');
            if (feedbackElement) {
                feedbackElement.textContent = fieldMessage || '';
            }
        });
    }

    /** Shows or hides an alert element and moves focus to it so screen readers announce it. */
    function showAlert(alertElement, message) {
        if (!message) {
            alertElement.classList.add('d-none');
            alertElement.textContent = '';
            return;
        }
        alertElement.textContent = message;
        alertElement.classList.remove('d-none');
        alertElement.focus();
    }

    /** Formats an ISO UTC timestamp in the viewer's local time zone. */
    function formatDateTime(isoTimestamp) {
        var parsedDate = new Date(isoTimestamp);
        return parsedDate.toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' });
    }

    /** Creates an element with optional class names and text (never parses HTML). */
    function createElement(tagName, classNames, textContent) {
        var newElement = document.createElement(tagName);
        if (classNames) {
            newElement.className = classNames;
        }
        if (textContent !== undefined && textContent !== null) {
            newElement.textContent = textContent;
        }
        return newElement;
    }

    /** Reads a value from the page's query string, or null. */
    function queryParameter(parameterName) {
        return new URLSearchParams(window.location.search).get(parameterName);
    }

    var lastWorkspaceStorageKey = 'muninn.lastWorkspaceId';

    /**
     * Remembers the workspace the user last opened (a per-browser convenience only; the API
     * decides access). Storage can be blocked, e.g. in private windows, so failures are ignored.
     */
    function rememberWorkspace(workspaceId) {
        try {
            window.localStorage.setItem(lastWorkspaceStorageKey, workspaceId);
        } catch (storageError) {
            // Not remembering is harmless.
        }
    }

    /** Returns the remembered workspace ID, or null. */
    function rememberedWorkspace() {
        try {
            return window.localStorage.getItem(lastWorkspaceStorageKey);
        } catch (storageError) {
            return null;
        }
    }

    /** Human-readable role names for the UI. */
    var roleLabels = { owner: 'Owner', admin: 'Admin', editor: 'Editor', reader: 'Reader' };

    function roleLabel(roleValue) {
        return roleLabels[roleValue] || roleValue;
    }

    window.MuninnApi = {
        ApiError: ApiError,
        request: request,
        uploadFile: uploadFile,
        loadCurrentUser: loadCurrentUser,
        signOut: signOut,
        goTo: goTo,
        showFieldErrors: showFieldErrors,
        showAlert: showAlert,
        formatDateTime: formatDateTime,
        createElement: createElement,
        queryParameter: queryParameter,
        rememberWorkspace: rememberWorkspace,
        rememberedWorkspace: rememberedWorkspace,
        roleLabel: roleLabel,
    };
})();

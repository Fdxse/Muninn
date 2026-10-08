/*
 * Search page: sends the query to the API (which searches only what the user may read) and
 * lists the results with the matched words highlighted. The query lives in the address, so a
 * search can be reloaded, bookmarked or reached from the search box on the notes page.
 * All text is inserted with textContent and <mark> elements, never innerHTML.
 */
(function () {
    'use strict';

    document.body.setAttribute('data-shell-hold', '');

    var pageContent = document.getElementById('shell-content');
    var adminNotice = document.getElementById('admin-account-notice');
    var searchForm = document.getElementById('search-form');
    var searchInput = document.getElementById('search-input');
    var includeArchivedCheckbox = document.getElementById('include-archived');
    var errorAlert = document.getElementById('search-error');
    var searchSummary = document.getElementById('search-summary');
    var resultsList = document.getElementById('search-results');

    /** Escapes a word for use inside a regular expression. */
    function escapeForRegularExpression(plainText) {
        return plainText.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    }

    /**
     * Appends text to a parent element, wrapping every occurrence of the search words in <mark>.
     * Matching ignores case. Built from text nodes only, so the note's text can never become HTML.
     */
    function appendHighlighted(parentElement, plainText, searchTerms) {
        if (searchTerms.length === 0 || plainText === '') {
            parentElement.appendChild(document.createTextNode(plainText));
            return;
        }
        var termPattern = new RegExp('(' + searchTerms.map(escapeForRegularExpression).join('|') + ')', 'gi');
        plainText.split(termPattern).forEach(function (textPiece, pieceIndex) {
            if (textPiece === '') {
                return;
            }
            // split() with a capture group puts the matched words at the odd positions.
            if (pieceIndex % 2 === 1) {
                parentElement.appendChild(MuninnApi.createElement('mark', 'px-0', textPiece));
            } else {
                parentElement.appendChild(document.createTextNode(textPiece));
            }
        });
    }

    /** Builds one result entry linking to the note. */
    function buildResultEntry(result, searchTerms) {
        var resultLink = MuninnApi.createElement('a', 'list-group-item list-group-item-action py-3');
        resultLink.href = 'note.php?id=' + encodeURIComponent(result.id);

        var titleLine = MuninnApi.createElement('div', 'd-flex justify-content-between align-items-baseline gap-2');
        var titleText = MuninnApi.createElement('span', 'fw-semibold text-break');
        appendHighlighted(titleText, result.title || 'Untitled', searchTerms);
        titleLine.appendChild(titleText);
        titleLine.appendChild(MuninnApi.createElement('small', 'text-muted-brand text-nowrap', MuninnApi.formatDateTime(result.updated_at)));
        resultLink.appendChild(titleLine);

        if (result.snippet) {
            var snippetLine = MuninnApi.createElement('div', 'small text-muted-brand muninn-search-snippet');
            appendHighlighted(snippetLine, result.snippet, searchTerms);
            resultLink.appendChild(snippetLine);
        }

        // Where the note lives: workspace, folder, Archive, tags.
        var placeLine = MuninnApi.createElement('div', 'd-flex flex-wrap gap-1 mt-1');
        var workspaceLabel = result.workspace_kind === 'personal' ? 'Personal' : result.workspace_name;
        placeLine.appendChild(MuninnApi.createElement('span', 'badge rounded-pill text-bg-light border', workspaceLabel));
        if (result.folder_name) {
            placeLine.appendChild(MuninnApi.createElement('span', 'badge rounded-pill text-bg-light border', result.folder_name));
        }
        if (result.archived_at) {
            placeLine.appendChild(MuninnApi.createElement('span', 'badge rounded-pill text-bg-secondary', 'Archived'));
        }
        result.tags.forEach(function (tagName) {
            var tagBadge = MuninnApi.createElement('span', 'badge rounded-pill muninn-tag-badge');
            appendHighlighted(tagBadge, '#' + tagName, searchTerms);
            placeLine.appendChild(tagBadge);
        });
        resultLink.appendChild(placeLine);
        return resultLink;
    }

    /** Runs the search named in the address bar and shows the results. */
    async function runSearchFromAddress() {
        var queryText = (MuninnApi.queryParameter('q') || '').trim();
        var includeArchived = MuninnApi.queryParameter('archived') === '1';
        searchInput.value = queryText;
        includeArchivedCheckbox.checked = includeArchived;
        resultsList.replaceChildren();
        MuninnApi.showAlert(errorAlert, '');
        if (queryText === '') {
            searchSummary.textContent = 'Search titles, text and tags in every workspace you belong to. Notes in Trash are not searched.';
            return;
        }

        searchSummary.textContent = 'Searching…';
        var searchParameters = new URLSearchParams({ q: queryText });
        if (includeArchived) {
            searchParameters.set('archived', '1');
        }
        try {
            var searchData = await MuninnApi.request('GET', '/api/v1/search?' + searchParameters.toString());
            searchData.results.forEach(function (result) {
                resultsList.appendChild(buildResultEntry(result, searchData.terms));
            });
            if (searchData.results.length === 0) {
                searchSummary.textContent = 'No notes found.';
            } else if (searchData.limited) {
                searchSummary.textContent = 'Showing the first ' + searchData.results.length + ' matches. Add more words to narrow the search.';
            } else {
                searchSummary.textContent = searchData.results.length + (searchData.results.length === 1 ? ' note found.' : ' notes found.');
            }
        } catch (searchError) {
            searchSummary.textContent = '';
            MuninnApi.showAlert(errorAlert, (searchError.fields && searchError.fields.q) || searchError.message);
        }
    }

    searchForm.addEventListener('submit', function (submitEvent) {
        submitEvent.preventDefault();
        var addressParameters = new URLSearchParams({ q: searchInput.value.trim() });
        if (includeArchivedCheckbox.checked) {
            addressParameters.set('archived', '1');
        }
        window.history.pushState(null, '', 'search.php?' + addressParameters.toString());
        runSearchFromAddress();
    });

    // Re-run when the archive option changes, if there is a query to run.
    includeArchivedCheckbox.addEventListener('change', function () {
        if (searchInput.value.trim() !== '') {
            searchForm.requestSubmit();
        }
    });

    // Back and forward buttons move between earlier searches.
    window.addEventListener('popstate', runSearchFromAddress);

    document.addEventListener('muninn:user-ready', async function (readyEvent) {
        if (readyEvent.detail.is_system_admin) {
            adminNotice.classList.remove('d-none');
            return;
        }
        pageContent.classList.remove('d-none');
        if (!MuninnApi.queryParameter('q')) {
            searchInput.focus();
        }
        await runSearchFromAddress();
    });
})();

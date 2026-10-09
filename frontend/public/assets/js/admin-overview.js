/*
 * The administrator's overview (D060). Draws everything from GET /api/v1/admin/overview with plain
 * DOM elements and CSS (no chart library). The API answers 404 to everyone but system
 * administrators and never sends note titles, folder names, tags or Magic Link labels.
 */
(function () {
    'use strict';

    document.body.setAttribute('data-shell-hold', '');

    var pageContent = document.getElementById('shell-content');
    var notAvailableAlert = document.getElementById('admin-not-available');
    var errorAlert = document.getElementById('overview-error');
    var revealButton = document.getElementById('reveal-usernames');
    var archiveButton = document.getElementById('audit-archive-button');
    var archiveStatus = document.getElementById('audit-archive-status');

    var weekdayNames = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
    var weekdayShortNames = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

    /** The last overview from the API, kept so the heat map toggles redraw without a new request. */
    var currentOverview = null;
    /** Whether the sign-in usernames are currently shown unmasked. */
    var usernamesRevealed = false;

    /** "1.2 GB", "340 kB": sizes in the decimal units file managers use. */
    function formatBytes(byteCount) {
        if (byteCount === null || byteCount === undefined) {
            return 'unknown';
        }
        var unitNames = ['bytes', 'kB', 'MB', 'GB', 'TB'];
        var unitIndex = 0;
        var scaledValue = byteCount;
        while (scaledValue >= 1000 && unitIndex < unitNames.length - 1) {
            scaledValue /= 1000;
            unitIndex++;
        }
        return (unitIndex === 0 ? scaledValue : scaledValue.toFixed(1)) + ' ' + unitNames[unitIndex];
    }

    /** Whole numbers with the viewer's thousands separator. */
    function formatNumber(numberValue) {
        return Number(numberValue).toLocaleString();
    }

    /** Fills a <dl> with label/value rows. */
    function fillDefinitionList(listElement, labelValuePairs) {
        listElement.replaceChildren();
        labelValuePairs.forEach(function (labelValuePair) {
            listElement.appendChild(MuninnApi.createElement('dt', 'col-7 fw-normal text-muted-brand', labelValuePair[0]));
            listElement.appendChild(MuninnApi.createElement('dd', 'col-5 mb-1 text-end fw-semibold', String(labelValuePair[1])));
        });
    }

    /** Fills a list group with "label ... value" rows, or one "None" row. */
    function fillValueList(listElement, rows, emptyText) {
        listElement.replaceChildren();
        if (rows.length === 0) {
            listElement.appendChild(MuninnApi.createElement('li', 'list-group-item px-0 text-muted-brand', emptyText));
            return;
        }
        rows.forEach(function (row) {
            var listItem = MuninnApi.createElement('li', 'list-group-item px-0 d-flex justify-content-between gap-2');
            var labelElement = MuninnApi.createElement('span', 'text-break', row.label);
            if (row.badge) {
                labelElement.appendChild(document.createTextNode(' '));
                labelElement.appendChild(MuninnApi.createElement('span', 'badge text-bg-warning', row.badge));
            }
            listItem.appendChild(labelElement);
            listItem.appendChild(MuninnApi.createElement('span', 'fw-semibold text-nowrap', row.value));
            listElement.appendChild(listItem);
        });
    }

    function renderWarnings(warnings) {
        var warningsList = document.getElementById('overview-warnings');
        warningsList.replaceChildren();
        if (warnings.length === 0) {
            var allGoodItem = MuninnApi.createElement('li', 'alert alert-success py-2 mb-0');
            allGoodItem.appendChild(MuninnApi.createElement('i', 'bi bi-check-circle me-2'));
            allGoodItem.lastChild.setAttribute('aria-hidden', 'true');
            allGoodItem.appendChild(document.createTextNode('Nothing needs attention right now.'));
            warningsList.appendChild(allGoodItem);
            return;
        }
        var alertClasses = { danger: 'alert-danger', warning: 'alert-warning', info: 'alert-secondary' };
        warnings.forEach(function (warning) {
            var warningItem = MuninnApi.createElement('li', 'alert ' + (alertClasses[warning.level] || 'alert-secondary') + ' py-2 mb-2');
            if (warning.level !== 'info') {
                var warningIcon = MuninnApi.createElement('i', 'bi bi-exclamation-triangle me-2');
                warningIcon.setAttribute('aria-hidden', 'true');
                warningItem.appendChild(warningIcon);
            }
            warningItem.appendChild(document.createTextNode(warning.message));
            warningsList.appendChild(warningItem);
        });
    }

    function renderTiles(overview) {
        var tilesRow = document.getElementById('overview-tiles');
        var diskFreeText = overview.storage.disk_free_bytes === null ? 'unknown' : formatBytes(overview.storage.disk_free_bytes);
        var diskDetail = overview.storage.disk_total_bytes
            ? Math.round(100 * overview.storage.disk_free_bytes / overview.storage.disk_total_bytes) + ' % of ' + formatBytes(overview.storage.disk_total_bytes)
            : '';
        var tiles = [
            ['Users', formatNumber(overview.users.active), overview.users.signed_in_last_7_days + ' signed in this week'],
            ['Notes', formatNumber(overview.content.notes_active), overview.content.notes_archived + ' archived, ' + overview.content.notes_in_trash + ' in Trash'],
            ['Images', formatNumber(overview.content.images), formatBytes(overview.content.image_bytes)],
            ['Disk free', diskFreeText, diskDetail],
        ];
        tilesRow.replaceChildren();
        tiles.forEach(function (tile) {
            var tileColumn = MuninnApi.createElement('div', 'col');
            var tileCard = MuninnApi.createElement('div', 'card h-100 muninn-stat-tile');
            var tileBody = MuninnApi.createElement('div', 'card-body p-3');
            tileBody.appendChild(MuninnApi.createElement('div', 'small text-muted-brand', tile[0]));
            tileBody.appendChild(MuninnApi.createElement('div', 'fs-4 fw-semibold', tile[1]));
            tileBody.appendChild(MuninnApi.createElement('div', 'small text-muted-brand', tile[2]));
            tileCard.appendChild(tileBody);
            tileColumn.appendChild(tileCard);
            tilesRow.appendChild(tileColumn);
        });
    }

    function renderUsers(users) {
        fillDefinitionList(document.getElementById('overview-users'), [
            ['Accounts', formatNumber(users.total)],
            ['Active', formatNumber(users.active)],
            ['Disabled', formatNumber(users.disabled)],
            ['Administrators', formatNumber(users.administrators)],
            ['Signed in, last 7 days', formatNumber(users.signed_in_last_7_days)],
            ['Signed in, last 30 days', formatNumber(users.signed_in_last_30_days)],
            ['Never signed in', formatNumber(users.never_signed_in)],
            ['Not signed in for 90+ days', formatNumber(users.inactive_90_days)],
            ['Open sessions now', formatNumber(users.open_sessions)],
            ['Open invitations', formatNumber(users.open_invitations)],
            ['Invitation requests waiting', formatNumber(users.pending_invitation_requests)],
        ]);
    }

    function renderContent(content, magicLinks) {
        fillDefinitionList(document.getElementById('overview-content'), [
            ['Notes (active / archived / Trash)', content.notes_active + ' / ' + content.notes_archived + ' / ' + content.notes_in_trash],
            ['Saved versions', formatNumber(content.note_versions)],
            ['Workspaces', formatNumber(content.workspaces)],
            ['Folders', formatNumber(content.folders)],
            ['Tags', formatNumber(content.tags)],
            ['Images', formatNumber(content.images) + ' (' + formatBytes(content.image_bytes) + ')'],
            ['Magic Links working now', formatNumber(magicLinks.active)],
            ['Magic Link visits, last 30 days', formatNumber(magicLinks.visits_last_30_days)],
        ]);

        // A bar per week; the tallest bar fills the box.
        var barsBox = document.getElementById('overview-weekly-notes');
        var largestCount = Math.max.apply(null, content.notes_created_per_week.map(function (week) { return week.count; }).concat([1]));
        var totalCount = 0;
        barsBox.replaceChildren();
        content.notes_created_per_week.forEach(function (week) {
            totalCount += week.count;
            var weekColumn = MuninnApi.createElement('div', 'muninn-week-bar-column');
            weekColumn.title = 'Week of ' + week.week_start + ': ' + week.count + ' notes';
            var weekBar = MuninnApi.createElement('div', 'muninn-week-bar');
            weekBar.style.height = Math.max(2, Math.round(100 * week.count / largestCount)) + '%';
            weekColumn.appendChild(weekBar);
            barsBox.appendChild(weekColumn);
        });
        var weeks = content.notes_created_per_week;
        document.getElementById('weekly-notes-summary').textContent = weeks.length === 0 ? '' :
            formatNumber(totalCount) + ' notes in the last ' + weeks.length + ' weeks (from the week of '
            + weeks[0].week_start + '); this week ' + weeks[weeks.length - 1].count + '.';
    }

    /** Shade level 0-4 for a count, relative to the busiest hour in the grid. */
    function heatLevel(eventCount, largestCount) {
        if (eventCount === 0) {
            return 0;
        }
        return Math.min(4, Math.max(1, Math.ceil(4 * eventCount / largestCount)));
    }

    function renderHeatMap() {
        if (currentOverview === null) {
            return;
        }
        var chosenKind = document.querySelector('input[name="activity-kind"]:checked').value;
        var chosenPeriod = document.querySelector('input[name="activity-period"]:checked').value;
        var grid = currentOverview.activity[chosenPeriod][chosenKind];
        var largestCount = 1;
        var totalCount = 0;
        grid.forEach(function (dayCounts) {
            dayCounts.forEach(function (hourCount) {
                largestCount = Math.max(largestCount, hourCount);
                totalCount += hourCount;
            });
        });

        var heatMapTable = document.getElementById('overview-heat-map');
        var caption = document.getElementById('heat-map-caption');
        var kindLabel = chosenKind === 'sign_ins' ? 'Sign-ins' : 'Magic Link visits';
        var periodLabel = chosenPeriod === 'last_4_weeks' ? 'the last 4 weeks' : 'the last 12 weeks';
        caption.textContent = kindLabel + ' per weekday and hour, ' + periodLabel;
        heatMapTable.replaceChildren(caption);

        // Header row: hours, labelled every third hour so it fits on a phone.
        var tableHead = MuninnApi.createElement('thead');
        var headerRow = MuninnApi.createElement('tr');
        headerRow.appendChild(MuninnApi.createElement('td'));
        for (var headerHour = 0; headerHour < 24; headerHour++) {
            var hourHeader = MuninnApi.createElement('th', 'muninn-heat-hour', headerHour % 3 === 0 ? String(headerHour).padStart(2, '0') : '');
            hourHeader.scope = 'col';
            hourHeader.setAttribute('aria-label', String(headerHour).padStart(2, '0') + ':00');
            headerRow.appendChild(hourHeader);
        }
        tableHead.appendChild(headerRow);
        heatMapTable.appendChild(tableHead);

        var tableBody = MuninnApi.createElement('tbody');
        grid.forEach(function (dayCounts, weekdayIndex) {
            var dayRow = MuninnApi.createElement('tr');
            var dayHeader = MuninnApi.createElement('th', 'muninn-heat-day', weekdayShortNames[weekdayIndex]);
            dayHeader.scope = 'row';
            dayHeader.setAttribute('aria-label', weekdayNames[weekdayIndex]);
            dayRow.appendChild(dayHeader);
            dayCounts.forEach(function (hourCount, hourIndex) {
                var hourCell = MuninnApi.createElement('td', 'muninn-heat-cell muninn-heat-' + heatLevel(hourCount, largestCount));
                var cellDescription = weekdayNames[weekdayIndex] + ' ' + String(hourIndex).padStart(2, '0') + ':00: ' + hourCount;
                hourCell.title = cellDescription;
                // The number itself, readable by screen readers; the colour shows it to everyone else.
                hourCell.appendChild(MuninnApi.createElement('span', 'visually-hidden', String(hourCount)));
                dayRow.appendChild(hourCell);
            });
            tableBody.appendChild(dayRow);
        });
        heatMapTable.appendChild(tableBody);

        document.getElementById('heat-map-total').textContent = formatNumber(totalCount) + ' in total, busiest hour ' + formatNumber(totalCount === 0 ? 0 : largestCount);
    }

    function renderSignInAttempts(attempts) {
        usernamesRevealed = attempts.usernames_revealed;
        revealButton.setAttribute('aria-pressed', usernamesRevealed ? 'true' : 'false');
        revealButton.querySelector('i').className = usernamesRevealed ? 'bi bi-eye-slash' : 'bi bi-eye';
        revealButton.querySelector('span').textContent = usernamesRevealed ? 'Hide usernames' : 'Reveal usernames';

        // Totals: periods as rows, outcomes as columns.
        var totalsTable = document.getElementById('sign-in-totals');
        var totalsCaption = totalsTable.querySelector('caption');
        totalsTable.replaceChildren(totalsCaption);
        var totalsHead = MuninnApi.createElement('thead');
        var totalsHeaderRow = MuninnApi.createElement('tr');
        ['Period', 'Succeeded', 'Failed', 'Blocked'].forEach(function (columnName, columnIndex) {
            var columnHeader = MuninnApi.createElement('th', columnIndex === 0 ? '' : 'text-end', columnName);
            columnHeader.scope = 'col';
            totalsHeaderRow.appendChild(columnHeader);
        });
        totalsHead.appendChild(totalsHeaderRow);
        totalsTable.appendChild(totalsHead);
        var totalsBody = MuninnApi.createElement('tbody');
        [['Last 24 hours', attempts.totals.last_24_hours], ['Last 7 days', attempts.totals.last_7_days], ['Last 30 days', attempts.totals.last_30_days]]
            .forEach(function (periodTotals) {
                var periodRow = MuninnApi.createElement('tr');
                var periodHeader = MuninnApi.createElement('th', 'fw-normal', periodTotals[0]);
                periodHeader.scope = 'row';
                periodRow.appendChild(periodHeader);
                periodRow.appendChild(MuninnApi.createElement('td', 'text-end', formatNumber(periodTotals[1].succeeded)));
                periodRow.appendChild(MuninnApi.createElement('td', 'text-end', formatNumber(periodTotals[1].failed)));
                periodRow.appendChild(MuninnApi.createElement('td', 'text-end', formatNumber(periodTotals[1].blocked)));
                totalsBody.appendChild(periodRow);
            });
        totalsTable.appendChild(totalsBody);

        fillValueList(document.getElementById('sign-in-top-usernames'), attempts.top_usernames.map(function (usernameRow) {
            return { label: usernameRow.username || '(empty)', badge: usernameRow.account_exists ? 'real account' : '', value: formatNumber(usernameRow.attempts) };
        }), 'No failed sign-ins.');
        fillValueList(document.getElementById('sign-in-top-addresses'), attempts.top_ip_addresses.map(function (addressRow) {
            return { label: addressRow.ip_address, value: formatNumber(addressRow.attempts) };
        }), 'No failed sign-ins.');

        // The latest failures, newest first.
        var recentTable = document.getElementById('sign-in-recent');
        var recentCaption = recentTable.querySelector('caption');
        recentTable.replaceChildren(recentCaption);
        if (attempts.recent_failures.length === 0) {
            var emptyBody = MuninnApi.createElement('tbody');
            var emptyRow = MuninnApi.createElement('tr');
            emptyRow.appendChild(MuninnApi.createElement('td', 'text-muted-brand', 'No failed sign-ins.'));
            emptyBody.appendChild(emptyRow);
            recentTable.appendChild(emptyBody);
            return;
        }
        var recentHead = MuninnApi.createElement('thead');
        var recentHeaderRow = MuninnApi.createElement('tr');
        ['When', 'Username', 'IP address', 'Result'].forEach(function (columnName) {
            var columnHeader = MuninnApi.createElement('th', '', columnName);
            columnHeader.scope = 'col';
            recentHeaderRow.appendChild(columnHeader);
        });
        recentHead.appendChild(recentHeaderRow);
        recentTable.appendChild(recentHead);
        var recentBody = MuninnApi.createElement('tbody');
        attempts.recent_failures.forEach(function (failure) {
            var failureRow = MuninnApi.createElement('tr');
            failureRow.appendChild(MuninnApi.createElement('td', 'text-nowrap', MuninnApi.formatDateTime(failure.at)));
            var usernameCell = MuninnApi.createElement('td', 'text-break', failure.username || '(empty)');
            if (failure.account_exists) {
                usernameCell.appendChild(document.createTextNode(' '));
                usernameCell.appendChild(MuninnApi.createElement('span', 'badge text-bg-warning', 'real account'));
            }
            failureRow.appendChild(usernameCell);
            failureRow.appendChild(MuninnApi.createElement('td', 'text-nowrap', failure.ip_address || ''));
            failureRow.appendChild(MuninnApi.createElement('td', '', failure.blocked ? 'Blocked' : 'Wrong password or name'));
            recentBody.appendChild(failureRow);
        });
        recentTable.appendChild(recentBody);
    }

    function renderStorage(overview) {
        var storage = overview.storage;
        var database = overview.database;
        fillDefinitionList(document.getElementById('overview-storage'), [
            ['Disk free', storage.disk_free_bytes === null ? 'unknown' : formatBytes(storage.disk_free_bytes) + (storage.disk_total_bytes ? ' of ' + formatBytes(storage.disk_total_bytes) : '')],
            ['Images', formatBytes(overview.content.image_bytes)],
            ['Database', formatBytes(database.total_bytes)],
            ['Application log', formatBytes(storage.log_file_bytes)],
            ['Log errors, last 24 hours', formatNumber(storage.log_errors_last_24_hours)],
            ['Audit log archives', formatBytes(storage.audit_archive_bytes)],
            ['Audit log rows', formatNumber(database.row_counts.audit_log)],
            ['Saved version rows', formatNumber(database.row_counts.note_versions)],
            ['Session rows', formatNumber(database.row_counts.sessions)],
            ['Database version', database.latest_migration || 'none'],
        ]);
        fillValueList(document.getElementById('overview-largest-tables'), database.largest_tables.map(function (tableRow) {
            return { label: tableRow.name, value: formatBytes(tableRow.byte_size) };
        }), 'No tables.');
    }

    function renderAuditLog(auditLog, zipAvailable) {
        document.getElementById('audit-archive-months').textContent = auditLog.archive_after_months;
        fillDefinitionList(document.getElementById('overview-audit'), [
            ['Entries', formatNumber(auditLog.entries)],
            ['Oldest entry', auditLog.oldest_entry_at ? MuninnApi.formatDateTime(auditLog.oldest_entry_at) : 'none'],
            ['Old enough to archive', formatNumber(auditLog.archivable_entries)],
        ]);
        archiveButton.disabled = auditLog.archivable_entries === 0 || !zipAvailable;

        var archivesList = document.getElementById('audit-archives');
        archivesList.replaceChildren();
        document.getElementById('audit-archives-empty').classList.toggle('d-none', auditLog.archives.length > 0);
        auditLog.archives.forEach(function (archive) {
            var archiveItem = MuninnApi.createElement('li', 'list-group-item px-0 d-flex flex-wrap align-items-center gap-2');
            var archiveText = MuninnApi.createElement('span', 'flex-grow-1 text-break', archive.file_name);
            archiveText.appendChild(MuninnApi.createElement('span', 'd-block text-muted-brand',
                formatBytes(archive.byte_size) + ', made ' + MuninnApi.formatDateTime(archive.created_at)));
            archiveItem.appendChild(archiveText);
            var downloadButton = MuninnApi.createElement('button', 'btn btn-outline-secondary btn-sm');
            downloadButton.type = 'button';
            var downloadIcon = MuninnApi.createElement('i', 'bi bi-download');
            downloadIcon.setAttribute('aria-hidden', 'true');
            downloadButton.appendChild(downloadIcon);
            downloadButton.appendChild(document.createTextNode(' Download'));
            downloadButton.setAttribute('aria-label', 'Download ' + archive.file_name);
            downloadButton.addEventListener('click', async function () {
                downloadButton.disabled = true;
                try {
                    await MuninnApi.downloadFile('/api/v1/admin/audit-log/archives/' + encodeURIComponent(archive.id), archive.file_name);
                } catch (downloadError) {
                    MuninnApi.showAlert(errorAlert, downloadError.message);
                } finally {
                    downloadButton.disabled = false;
                }
            });
            archiveItem.appendChild(downloadButton);
            archivesList.appendChild(archiveItem);
        });
    }

    function renderOverview(overview) {
        currentOverview = overview;
        document.getElementById('overview-generated').textContent = 'Updated ' + MuninnApi.formatDateTime(overview.generated_at) + '.';
        document.getElementById('activity-timezone').textContent = overview.timezone;
        renderWarnings(overview.warnings);
        renderTiles(overview);
        renderUsers(overview.users);
        renderContent(overview.content, overview.magic_links);
        renderHeatMap();
        renderSignInAttempts(overview.sign_in_attempts);
        renderStorage(overview);
        renderAuditLog(overview.audit_log, overview.software.zip_available);
        document.getElementById('overview-software').textContent = 'PHP ' + overview.software.php_version
            + ' · database ' + overview.software.database_version;
    }

    async function loadOverview() {
        renderOverview(await MuninnApi.request('GET', '/api/v1/admin/overview'));
    }

    document.querySelectorAll('input[name="activity-kind"], input[name="activity-period"]').forEach(function (toggleInput) {
        toggleInput.addEventListener('change', renderHeatMap);
    });

    document.getElementById('overview-refresh').addEventListener('click', async function (clickEvent) {
        var refreshButton = clickEvent.currentTarget;
        refreshButton.disabled = true;
        MuninnApi.showAlert(errorAlert, '');
        try {
            await loadOverview();
        } catch (refreshError) {
            MuninnApi.showAlert(errorAlert, refreshError.message);
        } finally {
            refreshButton.disabled = false;
        }
    });

    // Revealing asks the API again with ?reveal=true, which records the look in the audit log.
    revealButton.addEventListener('click', async function () {
        revealButton.disabled = true;
        try {
            var attemptsData = await MuninnApi.request('GET', '/api/v1/admin/sign-in-attempts' + (usernamesRevealed ? '' : '?reveal=true'));
            renderSignInAttempts(attemptsData.sign_in_attempts);
        } catch (revealError) {
            MuninnApi.showAlert(errorAlert, revealError.message);
        } finally {
            revealButton.disabled = false;
        }
    });

    archiveButton.addEventListener('click', async function () {
        var archivableCount = currentOverview ? currentOverview.audit_log.archivable_entries : 0;
        if (!window.confirm('Move ' + archivableCount + ' audit log entries into a zip file on the NAS and delete them from the database?')) {
            return;
        }
        archiveButton.disabled = true;
        archiveStatus.className = 'alert alert-secondary small';
        archiveStatus.textContent = 'Archiving…';
        try {
            var archiveData = await MuninnApi.request('POST', '/api/v1/admin/audit-log/archive');
            await loadOverview();
            archiveStatus.className = 'alert alert-success small';
            archiveStatus.textContent = formatNumber(archiveData.archive.entry_count) + ' entries moved into ' + archiveData.archive.file_name + '.';
        } catch (archiveError) {
            archiveButton.disabled = false;
            archiveStatus.className = 'alert alert-danger small';
            archiveStatus.textContent = archiveError.message;
        }
        archiveStatus.focus();
    });

    document.addEventListener('muninn:user-ready', async function () {
        try {
            await loadOverview();
            pageContent.classList.remove('d-none');
        } catch (loadError) {
            notAvailableAlert.classList.remove('d-none');
        }
    });
})();

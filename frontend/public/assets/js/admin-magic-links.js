/*
 * Magic Link overview for system administrators (D059): list every link and revoke any of them.
 * The API answers 404 to everyone else; it never sends link names, folder names or note titles here.
 */
(function () {
    'use strict';

    document.body.setAttribute('data-shell-hold', '');

    var pageContent = document.getElementById('shell-content');
    var notAvailableAlert = document.getElementById('admin-not-available');
    var errorAlert = document.getElementById('admin-links-error');
    var emptyMessage = document.getElementById('admin-links-empty');
    var linksList = document.getElementById('admin-links-list');

    var statusLabels = {
        active: 'Active',
        scheduled: 'Not started yet',
        expired: 'Expired',
        revoked: 'Revoked',
        creator_lost_access: 'Stopped: its creator lost access',
        target_gone: 'Stopped: the folder or note is gone',
    };
    var targetTypeLabels = { workspace: 'whole workspace', folder: 'one folder', note: 'one note' };

    /** One line of the overview, with a Revoke button while the link can still be used. */
    function buildLinkRow(magicLink) {
        var linkRow = MuninnApi.createElement('li', 'list-group-item');
        var headerLine = MuninnApi.createElement('div', 'd-flex flex-wrap align-items-center gap-2');
        var workspaceText = magicLink.workspace_kind === 'personal'
            ? 'Personal workspace of ' + magicLink.created_by_username
            : magicLink.workspace_name;
        headerLine.appendChild(MuninnApi.createElement('span', 'fw-semibold text-break', workspaceText));
        var isUsable = magicLink.status === 'active' || magicLink.status === 'scheduled';
        headerLine.appendChild(MuninnApi.createElement('span', 'badge ' + (isUsable ? 'text-bg-success' : 'text-bg-secondary'), statusLabels[magicLink.status] || magicLink.status));
        linkRow.appendChild(headerLine);

        linkRow.appendChild(MuninnApi.createElement('div', 'small',
            'Opens ' + targetTypeLabels[magicLink.target_type] + ', ' + (magicLink.permission === 'write' ? 'read and write' : 'read only')
            + ' · created by ' + magicLink.created_by_username + ' ' + MuninnApi.formatDateTime(magicLink.created_at)));
        var usageText = 'Works until ' + MuninnApi.formatDateTime(magicLink.valid_until) + ' · opened ' + magicLink.use_count + ' times';
        if (magicLink.last_used_at) {
            usageText += ', last ' + MuninnApi.formatDateTime(magicLink.last_used_at);
        }
        linkRow.appendChild(MuninnApi.createElement('div', 'small text-muted-brand', usageText));

        if (magicLink.status !== 'revoked' && magicLink.status !== 'expired') {
            var revokeButton = MuninnApi.createElement('button', 'btn btn-outline-danger btn-sm mt-2', 'Revoke');
            revokeButton.type = 'button';
            revokeButton.addEventListener('click', async function () {
                if (!window.confirm('Revoke this link? It stops working at once.')) {
                    return;
                }
                revokeButton.disabled = true;
                try {
                    await MuninnApi.request('DELETE', '/api/v1/admin/magic-links/' + encodeURIComponent(magicLink.id));
                    await loadLinks();
                } catch (revokeError) {
                    revokeButton.disabled = false;
                    MuninnApi.showAlert(errorAlert, revokeError.message);
                }
            });
            linkRow.appendChild(revokeButton);
        }
        return linkRow;
    }

    async function loadLinks() {
        var linksData = await MuninnApi.request('GET', '/api/v1/admin/magic-links');
        linksList.replaceChildren();
        linksData.magic_links.forEach(function (magicLink) {
            linksList.appendChild(buildLinkRow(magicLink));
        });
        emptyMessage.classList.toggle('d-none', linksData.magic_links.length > 0);
    }

    document.addEventListener('muninn:user-ready', async function () {
        try {
            await loadLinks();
            pageContent.classList.remove('d-none');
        } catch (loadError) {
            notAvailableAlert.classList.remove('d-none');
        }
    });
})();

(function ($) {
    const cards = document.querySelectorAll('.abm-card, .abm-hero');

    cards.forEach((card, index) => {
        card.style.opacity = '0';
        card.style.transform = 'translateY(10px)';

        window.setTimeout(() => {
            card.style.transition = 'opacity 240ms ease, transform 240ms ease';
            card.style.opacity = '1';
            card.style.transform = 'translateY(0)';
        }, index * 70);
    });

    const backupConfig = window.abmBackup || {};
    const startButton = document.querySelector('.abm-start-backup');
    const progressBox = document.querySelector('.abm-progress-box');
    const statusText = document.querySelector('.abm-status-text');
    const phasePill = document.querySelector('.abm-phase-pill');
    const fileBar = document.querySelector('.abm-file-bar');
    const dbBar = document.querySelector('.abm-db-bar');
    const filePercent = document.querySelector('.abm-file-percent');
    const dbPercent = document.querySelector('.abm-db-percent');
    const downloads = document.querySelector('.abm-downloads');
    const packageLink = document.querySelector('.abm-package-link');
    const installerLink = document.querySelector('.abm-installer-link');
    const compatibilityList = document.querySelector('.abm-compatibility-list');
    const syncButton = document.querySelector('.abm-generate-sync-token');
    const tokenBox = document.querySelector('.abm-token-box');
    const tokenId = document.querySelector('.abm-token-id');
    const tokenValue = document.querySelector('.abm-token-value');
    const tokenExpiry = document.querySelector('.abm-token-expiry');
    const syncLabel = document.querySelector('.abm-sync-label');
    const tabs = document.querySelectorAll('.abm-tab');
    const panels = document.querySelectorAll('.abm-tab-panel');
    const backupList = document.querySelector('.abm-backup-list');
    const managerStatus = document.querySelector('.abm-manager-status');
    const refreshBackups = document.querySelector('.abm-refresh-backups');
    const granularForm = document.querySelector('.abm-granular-form');
    const granularResult = document.querySelector('.abm-granular-result');
    const importForm = document.querySelector('.abm-import-form');
    const importResult = document.querySelector('.abm-import-result');

    if (!backupConfig.ajaxUrl) {
        return;
    }

    const escapeHtml = (value) => String(value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');

    const formatBytes = (bytes) => {
        const size = Number(bytes || 0);

        if (size < 1024) {
            return `${size} B`;
        }

        if (size < 1024 * 1024) {
            return `${(size / 1024).toFixed(1)} KB`;
        }

        return `${(size / (1024 * 1024)).toFixed(2)} MB`;
    };

    const managementRequest = (action, payload = {}) => $.post(backupConfig.ajaxUrl, {
        action,
        nonce: backupConfig.managementNonce,
        ...payload,
    });

    tabs.forEach((tab) => {
        tab.addEventListener('click', () => {
            const target = tab.dataset.tab;

            tabs.forEach((item) => item.classList.toggle('is-active', item === tab));
            panels.forEach((panel) => {
                const active = panel.dataset.panel === target;
                panel.classList.toggle('is-active', active);
                panel.hidden = !active;
            });

            if (target === 'manager') {
                loadBackups();
            }
        });
    });

    const renderBackups = (items) => {
        if (!backupList) {
            return;
        }

        if (!items.length) {
            backupList.innerHTML = `<tr><td colspan="5">${escapeHtml('No backup packages found.')}</td></tr>`;
            return;
        }

        backupList.innerHTML = items.map((item) => {
            const created = item.created_at ? new Date(Number(item.created_at) * 1000).toLocaleString() : '';
            const packageUrl = item.downloads && item.downloads.package ? item.downloads.package : '#';
            const installerUrl = item.downloads && item.downloads.installer ? item.downloads.installer : '';

            return `
                <tr>
                    <td><strong>${escapeHtml(item.label || item.package_name)}</strong><small>${escapeHtml(item.package_name || item.job_id)}</small></td>
                    <td><span class="abm-type-pill">${escapeHtml(item.type || 'full')}</span></td>
                    <td>${escapeHtml(formatBytes(item.size))}</td>
                    <td>${escapeHtml(created)}</td>
                    <td class="abm-table-actions">
                        <a class="button button-small" href="${packageUrl}">${escapeHtml('Download')}</a>
                        ${installerUrl && item.type === 'full' ? `<a class="button button-small" href="${installerUrl}">${escapeHtml('Installer')}</a>` : ''}
                        <button type="button" class="button button-small abm-delete-backup" data-job="${escapeHtml(item.job_id)}">${escapeHtml('Delete')}</button>
                    </td>
                </tr>
            `;
        }).join('');
    };

    const loadBackups = () => {
        if (!backupList) {
            return;
        }

        backupList.innerHTML = `<tr><td colspan="5">${escapeHtml(backupConfig.i18n.loading || 'Loading...')}</td></tr>`;

        managementRequest('abm_list_backups')
            .done((response) => {
                renderBackups(response.success && response.data ? response.data.items || [] : []);
            })
            .fail(() => {
                backupList.innerHTML = `<tr><td colspan="5">${escapeHtml('Unable to load backup packages.')}</td></tr>`;
            });
    };

    if (refreshBackups) {
        refreshBackups.addEventListener('click', loadBackups);
    }

    if (backupList) {
        backupList.addEventListener('click', (event) => {
            const button = event.target.closest('.abm-delete-backup');

            if (!button) {
                return;
            }

            if (!window.confirm(backupConfig.i18n.confirmDelete || 'Delete this backup package?')) {
                return;
            }

            button.disabled = true;

            managementRequest('abm_delete_backup', { job_id: button.dataset.job })
                .done(() => {
                    if (managerStatus) {
                        managerStatus.textContent = backupConfig.i18n.deleted || 'Backup deleted.';
                    }

                    loadBackups();
                })
                .fail((xhr) => {
                    button.disabled = false;
                    if (managerStatus) {
                        managerStatus.textContent = xhr.responseJSON && xhr.responseJSON.data ? xhr.responseJSON.data.message : 'Delete failed.';
                    }
                });
        });
    }

    const setProgress = (data) => {
        const fileValue = Number(data.file_progress || 0);
        const dbValue = Number(data.db_progress || 0);

        statusText.textContent = data.message || '';
        phasePill.textContent = data.phase || '';
        fileBar.style.width = `${fileValue}%`;
        dbBar.style.width = `${dbValue}%`;
        filePercent.textContent = `${fileValue}%`;
        dbPercent.textContent = `${dbValue}%`;

        if (compatibilityList && data.compatibility) {
            compatibilityList.innerHTML = Object.entries(data.compatibility)
                .map(([slug, item]) => {
                    const status = item.active ? 'active' : 'not detected';
                    return `<li><strong>${escapeHtml(slug)}</strong><span>${escapeHtml(status)} · ${Number(item.tables || 0)} tables · ${Number(item.required_file_prefixes || 0)} media rules</span></li>`;
                })
                .join('');
        }
    };

    const request = (action, payload = {}) => $.post(backupConfig.ajaxUrl, {
        action,
        nonce: backupConfig.nonce,
        ...payload,
    });

    const processJob = (jobId) => {
        request('abm_process_backup', { job_id: jobId })
            .done((response) => {
                if (!response.success) {
                    throw new Error(backupConfig.i18n.failed);
                }

                const data = response.data;
                setProgress(data);

                if (data.status === 'completed') {
                    startButton.disabled = false;
                    startButton.textContent = backupConfig.i18n.completed;

                    if (data.downloads) {
                        packageLink.href = data.downloads.package || '#';
                        installerLink.href = data.downloads.installer || '#';
                        downloads.hidden = false;
                    }

                    return;
                }

                window.setTimeout(() => processJob(jobId), 450);
            })
            .fail((xhr) => {
                const message = xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message
                    ? xhr.responseJSON.data.message
                    : backupConfig.i18n.failed;

                startButton.disabled = false;
                statusText.textContent = message;
                progressBox.classList.add('is-error');
        });
    };

    if (startButton && progressBox) {
        startButton.addEventListener('click', () => {
        startButton.disabled = true;
        startButton.textContent = backupConfig.i18n.starting;
        progressBox.hidden = false;
        progressBox.classList.remove('is-error');
        downloads.hidden = true;

        request('abm_start_backup')
            .done((response) => {
                if (!response.success) {
                    throw new Error(backupConfig.i18n.failed);
                }

                setProgress(response.data);
                processJob(response.data.job_id);
            })
            .fail(() => {
                startButton.disabled = false;
                statusText.textContent = backupConfig.i18n.failed;
                progressBox.classList.add('is-error');
            });
        });
    }

    if (granularForm && granularResult) {
        granularForm.addEventListener('submit', (event) => {
            event.preventDefault();

            const button = granularForm.querySelector('button[type="submit"]');
            const formData = new FormData(granularForm);
            const payload = {};

            formData.forEach((value, key) => {
                payload[key] = value;
            });

            button.disabled = true;
            button.textContent = backupConfig.i18n.exporting || 'Creating granular export...';
            granularResult.hidden = false;
            granularResult.className = 'abm-granular-result';
            granularResult.textContent = button.textContent;

            managementRequest('abm_create_granular_export', payload)
                .done((response) => {
                    const data = response.data || {};
                    const packageUrl = data.downloads && data.downloads.package ? data.downloads.package : '#';
                    granularResult.innerHTML = `<strong>${escapeHtml('Granular package is ready.')}</strong> <a class="button button-primary" href="${packageUrl}">${escapeHtml('Download ZIP')}</a>`;
                    loadBackups();
                })
                .fail((xhr) => {
                    granularResult.classList.add('is-error');
                    granularResult.textContent = xhr.responseJSON && xhr.responseJSON.data ? xhr.responseJSON.data.message : 'Granular export failed.';
                })
                .always(() => {
                    button.disabled = false;
                    button.textContent = 'Create Granular Package';
                });
        });
    }

    if (importForm && importResult) {
        importForm.addEventListener('submit', (event) => {
            event.preventDefault();

            const button = importForm.querySelector('button[type="submit"]');
            const formData = new FormData(importForm);
            formData.append('action', 'abm_smart_import');
            formData.append('nonce', backupConfig.managementNonce);

            button.disabled = true;
            button.textContent = backupConfig.i18n.importing || 'Importing package...';
            importResult.hidden = false;
            importResult.className = 'abm-import-result';
            importResult.textContent = button.textContent;

            $.ajax({
                url: backupConfig.ajaxUrl,
                method: 'POST',
                data: formData,
                processData: false,
                contentType: false,
            })
                .done((response) => {
                    const data = response.data || {};
                    importResult.innerHTML = `<strong>${escapeHtml(data.message || 'Import completed.')}</strong>`;
                })
                .fail((xhr) => {
                    importResult.classList.add('is-error');
                    importResult.textContent = xhr.responseJSON && xhr.responseJSON.data ? xhr.responseJSON.data.message : 'Import failed.';
                })
                .always(() => {
                    button.disabled = false;
                    button.textContent = 'Upload & Import';
                });
        });
    }

    if (syncButton && tokenBox) {
        syncButton.addEventListener('click', () => {
            syncButton.disabled = true;

            $.post(backupConfig.ajaxUrl, {
                action: 'abm_generate_sync_token',
                nonce: backupConfig.syncNonce,
                label: syncLabel ? syncLabel.value : '',
            })
                .done((response) => {
                    if (!response.success) {
                        throw new Error('Token generation failed.');
                    }

                    const data = response.data;
                    tokenId.textContent = data.token_id;
                    tokenValue.textContent = data.token;
                    tokenExpiry.textContent = `${backupConfig.i18n.tokenReady} ${new Date(data.expires_at * 1000).toLocaleString()}`;
                    tokenBox.hidden = false;
                })
                .always(() => {
                    syncButton.disabled = false;
                });
        });
    }

    loadBackups();
})(jQuery);

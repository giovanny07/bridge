(function () {
    function ready(fn) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', fn);
        } else {
            fn();
        }
    }

    function showConnectionTestResult(result, html, state) {
        if (result) {
            result.className = 'bridge-test-result small bridge-test-result-' + state;
            result.innerHTML = html;
        }
    }

    function parseJsonResponse(response) {
        return response.text().then(function (text) {
            var data = {};
            if (text !== '') {
                try {
                    data = JSON.parse(text);
                } catch (e) {
                    throw new Error('Invalid JSON response');
                }
            }
            if (!response.ok) {
                throw new Error((data && data.message) ? data.message : 'HTTP ' + response.status);
            }
            return data;
        });
    }

    function runConnectionTest(btn) {
        var id = btn.dataset.id;
        var token = btn.dataset.token;
        var ajaxUrl = btn.dataset.ajax;
        var result = document.getElementById('bridge-test-result-' + id);
        var testing = btn.dataset.testing || 'Testing...';
        var failed = btn.dataset.failed || 'Request failed.';
        var recordsLabel = btn.dataset.recordsLabel || 'records';
        var originalHtml = btn.dataset.originalHtml || btn.innerHTML;
        btn.dataset.originalHtml = originalHtml;

        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status"></span>';
        showConnectionTestResult(
            result,
            '<i class="ti ti-loader-2 me-1"></i>' + testing,
            'testing'
        );

        fetch(ajaxUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-Requested-With': 'XMLHttpRequest',
                'X-Glpi-Csrf-Token': token
            },
            body: '_glpi_csrf_token=' + encodeURIComponent(token) + '&id=' + encodeURIComponent(id)
        })
            .then(parseJsonResponse)
            .then(function (data) {
                if (data.ok) {
                    showConnectionTestResult(
                        result,
                        '<i class="ti ti-circle-check me-1"></i>'
                        + data.latency_ms + 'ms &mdash; '
                        + Number(data.total || 0).toLocaleString() + ' ' + recordsLabel,
                        'success'
                    );
                } else {
                    showConnectionTestResult(
                        result,
                        '<i class="ti ti-circle-x me-1"></i>'
                        + (data.message || failed)
                        + (data.status ? ' (HTTP ' + data.status + ')' : ''),
                        'error'
                    );
                }
            })
            .catch(function (error) {
                showConnectionTestResult(
                    result,
                    '<i class="ti ti-circle-x me-1"></i>'
                    + (error.message || failed),
                    'error'
                );
            })
            .finally(function () {
                btn.disabled = false;
                btn.innerHTML = originalHtml;
            });
    }

    function initConnectionTests() {
        if (window.bridgeConnectionTestsBound) {
            return;
        }
        window.bridgeConnectionTestsBound = true;

        document.addEventListener('click', function (event) {
            var btn = event.target.closest('.bridge-test-btn');
            if (!btn) {
                return;
            }
            event.preventDefault();
            runConnectionTest(btn);
        });
    }

    function executeScripts(element) {
        element.querySelectorAll('script').forEach(function (oldScript) {
            var newScript = document.createElement('script');
            Array.from(oldScript.attributes).forEach(function (attr) {
                newScript.setAttribute(attr.name, attr.value);
            });
            newScript.textContent = oldScript.textContent;
            oldScript.parentNode.replaceChild(newScript, oldScript);
        });
    }

    function loadEditForm(id) {
        var panel = document.getElementById('bridge-connection-form-panel');
        if (!panel) return;
        var ajaxBase = panel.dataset.ajaxBase;
        if (!ajaxBase) return;

        panel.innerHTML = '<div class="d-flex justify-content-center p-5">'
            + '<div class="spinner-border text-secondary" role="status">'
            + '<span class="visually-hidden">Loading...</span>'
            + '</div></div>';

        fetch(ajaxBase + '/ajax/edit_form.php?id=' + encodeURIComponent(id), {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
            .then(function (r) {
                if (!r.ok) throw new Error('HTTP ' + r.status);
                return r.text();
            })
            .then(function (html) {
                panel.innerHTML = html;
                executeScripts(panel);
                // Re-init confirm buttons in the injected form
                initConfirmButtons(panel);
            })
            .catch(function (err) {
                panel.innerHTML = '<div class="alert alert-danger m-3">Could not load form: ' + err.message + '</div>';
            });
    }

    function initEditForm() {
        if (window.bridgeEditFormBound) return;
        window.bridgeEditFormBound = true;

        document.addEventListener('click', function (event) {
            var btn = event.target.closest('[data-bridge-edit-id]');
            if (!btn) return;
            event.preventDefault();
            loadEditForm(btn.dataset.bridgeEditId);
        });
    }

    function initConfirmButtons(root) {
        (root || document).querySelectorAll('[data-bridge-confirm]').forEach(function (el) {
            if (el.dataset.bridgeConfirmBound) return;
            el.dataset.bridgeConfirmBound = '1';
            el.addEventListener('click', function (event) {
                if (!window.confirm(el.dataset.bridgeConfirm)) {
                    event.preventDefault();
                }
            });
        });
    }

    function initCopyButtons() {
        document.querySelectorAll('.bridge-copy-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var target = document.getElementById(btn.dataset.copyTarget);
                if (!target || !navigator.clipboard) {
                    return;
                }
                var copied = btn.dataset.copied || 'Copied';
                var copy = btn.dataset.copy || 'Copy';
                navigator.clipboard.writeText(target.textContent).then(function () {
                    btn.innerHTML = '<i class="ti ti-check me-1"></i>' + copied;
                    setTimeout(function () {
                        btn.innerHTML = '<i class="ti ti-copy me-1"></i>' + copy;
                    }, 2000);
                });
            });
        });
    }

    function setBridgeMode(mode) {
        var isIds = mode === 'ids';
        var ids = document.getElementById('bridge_section_ids');
        var filters = document.getElementById('bridge_section_filters');
        var modeValue = document.getElementById('migration_mode_val');
        var btnFilters = document.getElementById('btn-mode-filters');
        var btnIds = document.getElementById('btn-mode-ids');

        if (ids) ids.style.display = isIds ? '' : 'none';
        if (filters) filters.style.display = isIds ? 'none' : '';
        if (modeValue) modeValue.value = mode;
        if (btnFilters) btnFilters.classList.toggle('active', !isIds);
        if (btnIds) btnIds.classList.toggle('active', isIds);
    }

    function setUserMode(mode) {
        var isIds = mode === 'ids';
        var ids = document.getElementById('bridge_user_section_ids');
        var all = document.getElementById('bridge_user_section_all');
        var modeValue = document.getElementById('sync_mode_val');
        var btnAll = document.getElementById('btn-mode-all');
        var btnIds = document.getElementById('btn-mode-ids');

        if (ids) ids.style.display = isIds ? '' : 'none';
        if (all) all.style.display = isIds ? 'none' : '';
        if (modeValue) modeValue.value = mode;
        if (btnAll) btnAll.classList.toggle('active', !isIds);
        if (btnIds) btnIds.classList.toggle('active', isIds);
    }

    function setPeriod(radio) {
        if (!radio) return;
        document.querySelectorAll('.bridge-period-option').forEach(function (el) {
            el.classList.remove('active');
            el.querySelectorAll('.bridge-period-extra').forEach(function (x) { x.style.display = 'none'; });
        });
        var label = radio.closest('.bridge-period-option');
        if (!label) return;
        label.classList.add('active');
        var extra = label.querySelector('.bridge-period-extra');
        if (extra) extra.style.display = '';
        ['f_created_after', 'f_updated_after', 'f_start_page'].forEach(function (id) {
            var el = document.getElementById(id);
            if (el && !label.contains(el)) el.value = el.type === 'number' ? '1' : '';
        });
    }

    function getStorageKey(form) {
        return form && form.dataset.storageKey ? form.dataset.storageKey : 'bridge_form';
    }

    function getFieldValue(form, id) {
        var el = form ? form.querySelector('#' + id) : document.getElementById(id);
        return el ? el.value : '';
    }

    function refreshFormCsrfToken(form) {
        var input = form.querySelector('input[name="_glpi_csrf_token"]');
        if (!input || typeof window.getAjaxCsrfToken !== 'function') return;
        try {
            var token = window.getAjaxCsrfToken();
            if (token) input.value = token;
        } catch (e) {}
    }

    function clearInactivePeriodFields(form) {
        var activePeriod = form.querySelector('input[name=time_period]:checked');
        var period = activePeriod ? activePeriod.value : 'recent';
        var createdAfter = form.querySelector('#f_created_after');
        var updatedAfter = form.querySelector('#f_updated_after');
        var startPage = form.querySelector('#f_start_page');

        if (period !== 'from_date' && createdAfter) createdAfter.value = '';
        if (period !== 'incremental' && updatedAfter) updatedAfter.value = '';
        if (period !== 'manual' && startPage) startPage.value = '1';
    }

    function clearInactiveModeFields(form) {
        var mode = getFieldValue(form, 'migration_mode_val') || 'filters';
        var sourceIds = form.querySelector('#f_source_ids');

        if (mode !== 'ids' && sourceIds) sourceIds.value = '';
    }

    function validateMigrateForm(form) {
        var mode = getFieldValue(form, 'migration_mode_val') || 'filters';
        var periodRadio = form.querySelector('input[name=time_period]:checked');
        var period = periodRadio ? periodRadio.value : 'recent';
        var sourceIds = form.querySelector('#f_source_ids');
        var createdAfter = form.querySelector('#f_created_after');
        var updatedAfter = form.querySelector('#f_updated_after');

        [sourceIds, createdAfter, updatedAfter].forEach(function (el) {
            if (el) el.setCustomValidity('');
        });

        if (mode === 'ids' && sourceIds && sourceIds.value.trim() === '') {
            sourceIds.setCustomValidity('Enter at least one ticket number or source ID.');
            sourceIds.reportValidity();
            return false;
        }

        if (mode === 'filters' && period === 'from_date' && createdAfter && createdAfter.value.trim() === '') {
            createdAfter.setCustomValidity('Select a From date before continuing.');
            createdAfter.reportValidity();
            return false;
        }

        if (mode === 'filters' && period === 'incremental' && updatedAfter && updatedAfter.value.trim() === '') {
            updatedAfter.setCustomValidity('Select an Incremental date before continuing.');
            updatedAfter.reportValidity();
            return false;
        }

        return true;
    }

    function restoreMigrateForm(form) {
        if (!form || form.dataset.bridgeInitialized === '1') return;
        form.dataset.bridgeInitialized = '1';
        var storageKey = getStorageKey(form);

        form.querySelectorAll('.bridge-state-pill input[type=radio]').forEach(function (radio) {
            if (radio.checked) {
                var statePill = radio.closest('.bridge-state-pill');
                if (statePill) statePill.classList.add('active');
            }
        });

        form.querySelectorAll('input[name=time_period]').forEach(function (radio) {
            if (radio.checked) setPeriod(radio);
        });

        try {
            setBridgeMode(sessionStorage.getItem(storageKey + '_mode') || 'filters');
            var period = sessionStorage.getItem(storageKey + '_period') || 'recent';
            var pRadio = form.querySelector('input[name=time_period][value="' + period + '"]');
            if (pRadio) {
                pRadio.checked = true;
                setPeriod(pRadio);
            }
            ['ids', 'created_after', 'updated_after', 'start_page', 'limit'].forEach(function (key) {
                var value = sessionStorage.getItem(storageKey + '_' + key);
                var el = form.querySelector('#' + (key === 'ids' ? 'f_source_ids' : 'f_' + key));
                if (value && el) el.value = value;
            });
        } catch (e) {}
    }

    function initMigrateForm() {
        var form = document.getElementById('bridge-migrate-form');
        if (form) restoreMigrateForm(form);
        if (window.bridgeMigrateFormBound) return;
        window.bridgeMigrateFormBound = true;

        document.addEventListener('click', function (event) {
            var btn = event.target.closest('[data-bridge-mode]');
            if (!btn) return;
            var activeForm = btn.closest('#bridge-migrate-form');
            if (!activeForm) return;
            restoreMigrateForm(activeForm);
            setBridgeMode(btn.dataset.bridgeMode);
            try { sessionStorage.setItem(getStorageKey(activeForm) + '_mode', btn.dataset.bridgeMode); } catch (e) {}
        });

        document.addEventListener('change', function (event) {
            var radio = event.target.closest('.bridge-pill input[type=radio]');
            if (!radio) return;
            var parent = radio.closest('.d-flex');
            if (parent) parent.querySelectorAll('.bridge-pill').forEach(function (p) { p.classList.remove('active'); });
            var pill = radio.closest('.bridge-pill');
            if (pill) pill.classList.add('active');
        });

        document.addEventListener('change', function (event) {
            var radio = event.target.closest('.bridge-state-pill input[type=radio]');
            if (!radio) return;
            var activeForm = radio.closest('#bridge-migrate-form');
            if (!activeForm) return;
            activeForm.querySelectorAll('.bridge-state-pill').forEach(function (p) { p.classList.remove('active'); });
            var pill = radio.closest('.bridge-state-pill');
            if (pill) pill.classList.add('active');
        });

        document.addEventListener('change', function (event) {
            var radio = event.target.closest('input[name=time_period]');
            if (!radio || !radio.closest('#bridge-migrate-form')) return;
            var activeForm = radio.closest('#bridge-migrate-form');
            restoreMigrateForm(activeForm);
            setPeriod(radio);
            try { sessionStorage.setItem(getStorageKey(activeForm) + '_period', radio.value); } catch (e) {}
        });

        document.addEventListener('submit', function (event) {
            var activeForm = event.target.closest('#bridge-migrate-form');
            if (!activeForm) return;
            restoreMigrateForm(activeForm);
            refreshFormCsrfToken(activeForm);
            clearInactiveModeFields(activeForm);
            clearInactivePeriodFields(activeForm);
            if (!validateMigrateForm(activeForm)) {
                event.preventDefault();
                return;
            }
            var storageKey = getStorageKey(activeForm);
            try {
                sessionStorage.setItem(storageKey + '_mode', getFieldValue(activeForm, 'migration_mode_val'));
                sessionStorage.setItem(storageKey + '_ids', getFieldValue(activeForm, 'f_source_ids'));
                sessionStorage.setItem(storageKey + '_created_after', getFieldValue(activeForm, 'f_created_after'));
                sessionStorage.setItem(storageKey + '_updated_after', getFieldValue(activeForm, 'f_updated_after'));
                sessionStorage.setItem(storageKey + '_start_page', getFieldValue(activeForm, 'f_start_page'));
                sessionStorage.setItem(storageKey + '_limit', getFieldValue(activeForm, 'f_limit'));
            } catch (e) {}
        });

        window.addEventListener('pageshow', function (event) {
            if (event.persisted && document.getElementById('bridge-migrate-form')) {
                window.location.reload();
            }
        });
    }

    function initUserSyncForm() {
        var form = document.getElementById('bridge-usersync-form');
        if (!form) return;
        var storageKey = form.dataset.storageKey || 'bridge_usersync';

        document.querySelectorAll('[data-bridge-user-mode]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                setUserMode(btn.dataset.bridgeUserMode);
                try { sessionStorage.setItem(storageKey + '_mode', btn.dataset.bridgeUserMode); } catch (e) {}
            });
        });

        try {
            var mode = sessionStorage.getItem(storageKey + '_mode');
            if (mode) setUserMode(mode);
            var ids = sessionStorage.getItem(storageKey + '_ids');
            var idsInput = document.getElementById('f_source_ids');
            if (ids && idsInput) idsInput.value = ids;
        } catch (e) {}

        form.addEventListener('submit', function () {
            try {
                sessionStorage.setItem(storageKey + '_mode', document.getElementById('sync_mode_val').value);
                sessionStorage.setItem(storageKey + '_ids', document.getElementById('f_source_ids').value);
            } catch (e) {}
        });
    }

    function initHistoryBulk() {
        var form = document.getElementById('bridge-history-form');
        if (!form) return;
        var checkAll = document.getElementById('bridge-check-all');
        var purgeBtn = document.getElementById('bridge-purge-sel');
        var selCount = document.getElementById('bridge-sel-count');
        var rows = document.querySelectorAll('.bridge-row-check');
        var actionInput = document.getElementById('bridge-action-input');

        function updateBulk() {
            var checked = document.querySelectorAll('.bridge-row-check:checked').length;
            purgeBtn.style.display = checked > 0 ? '' : 'none';
            selCount.style.display = checked > 0 ? '' : 'none';
            selCount.textContent = checked + ' selected';
            checkAll.indeterminate = checked > 0 && checked < rows.length;
            checkAll.checked = checked > 0 && checked === rows.length;
        }

        if (checkAll) {
            checkAll.addEventListener('change', function () {
                rows.forEach(function (cb) { cb.checked = checkAll.checked; });
                updateBulk();
            });
        }
        rows.forEach(function (cb) { cb.addEventListener('change', updateBulk); });

        document.querySelectorAll('[data-bridge-history-action]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                if (btn.dataset.confirm && !window.confirm(btn.dataset.confirm)) return;
                actionInput.value = btn.dataset.bridgeHistoryAction;
                form.submit();
            });
        });

        initConfirmButtons();
    }

    ready(function () {
        initConnectionTests();
        initEditForm();
        initCopyButtons();
        initMigrateForm();
        initUserSyncForm();
        initHistoryBulk();
        initConfirmButtons();
    });
})();

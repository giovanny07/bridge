<?php

namespace GlpiPlugin\Bridge\Page;

use GlpiPlugin\Bridge\Connection;
use GlpiPlugin\Bridge\Migration\BridgeJob;

class JobStatusPage
{
    public static function show(BridgeJob $job, Connection $connection): void
    {
        $jobId      = $job->id();
        $base       = Connection::getPluginBaseURL();
        $ajaxUrl    = self::h($base . '/ajax/job_status.php');
        $historyUrl = $base . '/front/migration_history.php';
        $connId     = $job->connectionId();
        $isFinished = $job->isFinished();

        echo '<div class="container-fluid py-3 px-4" style="max-width:780px">';

        // ── Header ────────────────────────────────────────────────────────
        echo '<div class="d-flex align-items-center justify-content-between mb-4">';
        echo '<div>';
        echo '<h4 class="mb-0"><i class="ti ti-loader me-2 text-primary" id="bridge-job-spinner"></i>';
        echo self::h(__('Migration job', 'bridge')) . ' <span class="text-muted small">#' . $jobId . '</span></h4>';
        echo '<div class="text-muted small mt-1"><i class="ti ti-plug me-1"></i><strong>' . self::h($connection->fields['name']) . '</strong>';
        echo ' &mdash; ' . self::h($job->resourceType()) . '</div>';
        echo '</div>';
        echo '<div class="d-flex gap-2">';
        echo '<a class="btn btn-sm btn-outline-secondary" href="' . self::h($historyUrl . '?id=' . $connId) . '">';
        echo '<i class="ti ti-history me-1"></i>' . self::h(__('History', 'bridge')) . '</a>';
        echo '<a class="btn btn-sm btn-outline-secondary" href="' . self::h(Connection::getConfigURL($connId)) . '">';
        echo '<i class="ti ti-arrow-left me-1"></i>' . self::h(__('Back', 'bridge')) . '</a>';
        echo '</div>';
        echo '</div>';

        // ── Status card ───────────────────────────────────────────────────
        echo '<div class="card border-0 shadow-sm mb-4" id="bridge-job-card">';
        echo '<div class="card-body">';

        // Status badge
        echo '<div class="d-flex align-items-center justify-content-between mb-3">';
        echo '<span id="bridge-status-badge" class="badge fs-6 ' . self::statusClass($job->status()) . '">';
        echo self::h(self::statusLabel($job->status()));
        echo '</span>';
        echo '<span class="text-muted small" id="bridge-heartbeat"></span>';
        echo '</div>';

        // Stats grid
        echo '<div class="row g-3 mb-3" id="bridge-stats-grid">';
        self::statBox('circle-check', 'success',   0, __('Created', 'bridge'),  'bridge-stat-created');
        self::statBox('circle-x',     'danger',     0, __('Failed', 'bridge'),   'bridge-stat-failed');
        self::statBox('minus-circle', 'secondary',  0, __('Skipped', 'bridge'),  'bridge-stat-skipped');
        self::statBox('radar',        'primary',    0, __('Scanned', 'bridge'),  'bridge-stat-scanned');
        echo '</div>';

        echo '<div class="border rounded p-2 mb-3 bg-light">';
        echo '<div class="text-muted small fw-semibold mb-2"><i class="ti ti-route me-1"></i>' . self::h(__('Pipeline counters', 'bridge')) . '</div>';
        echo '<div class="row g-2 small" id="bridge-pipeline-grid">';
        self::metricPill(__('API pages', 'bridge'), 'bridge-metric-api_pages');
        self::metricPill(__('Queued', 'bridge'), 'bridge-metric-queued');
        self::metricPill(__('Duplicates', 'bridge'), 'bridge-metric-duplicates');
        self::metricPill(__('Comments', 'bridge'), 'bridge-metric-comments_read');
        self::metricPill(__('Followups', 'bridge'), 'bridge-metric-followups_created');
        self::metricPill(__('Attachments', 'bridge'), 'bridge-metric-attachments_downloaded');
        self::metricPill(__('Attachment fails', 'bridge'), 'bridge-metric-attachments_failed');
        self::metricPill(__('Documents', 'bridge'), 'bridge-metric-documents_linked');
        echo '</div></div>';

        echo '<div class="border rounded p-2 mb-3">';
        echo '<div class="text-muted small fw-semibold mb-2"><i class="ti ti-clock-bolt me-1"></i>' . self::h(__('Stage duration', 'bridge')) . '</div>';
        echo '<div class="row g-2 small" id="bridge-timing-grid">';
        self::metricPill(__('API', 'bridge'), 'bridge-time-api');
        self::metricPill(__('Dedupe', 'bridge'), 'bridge-time-dedupe');
        self::metricPill(__('Mapping', 'bridge'), 'bridge-time-map');
        self::metricPill(__('Comments', 'bridge'), 'bridge-time-comments');
        self::metricPill(__('Create', 'bridge'), 'bridge-time-ticket_create');
        self::metricPill(__('Followups', 'bridge'), 'bridge-time-followups');
        self::metricPill(__('Attachments', 'bridge'), 'bridge-time-attachments');
        self::metricPill(__('Total', 'bridge'), 'bridge-time-total');
        echo '</div></div>';

        // Error message
        echo '<div id="bridge-error-msg" class="alert alert-danger py-2 small" style="display:none"></div>';

        // Actions
        $stats   = $job->stats();
        $hasFailed = ($stats['failed'] ?? 0) > 0;
        echo '<div class="d-flex flex-wrap gap-2" id="bridge-job-actions">';
        $isRolledBack = $job->status() === BridgeJob::STATUS_ROLLED_BACK;
        if (!$isFinished) {
            echo '<button class="btn btn-sm btn-outline-danger" onclick="bridgeJobAction(\'cancel\', \'' . self::h(__('Cancel this migration job?', 'bridge')) . '\')">';
            echo '<i class="ti ti-player-stop me-1"></i>' . self::h(__('Cancel', 'bridge'));
            echo '</button>';
        } elseif (!$isRolledBack) {
            echo '<button class="btn btn-sm btn-outline-primary" onclick="bridgeJobAction(\'retry\', \'' . self::h(__('Retry this job from the beginning?', 'bridge')) . '\')">';
            echo '<i class="ti ti-refresh me-1"></i>' . self::h(__('Retry job', 'bridge'));
            echo '</button>';
        }
        if ($hasFailed && !$isRolledBack) {
            echo '<button class="btn btn-sm btn-outline-warning" onclick="bridgeJobAction(\'retry_failed_records\', \'' . self::h(__('Retry failed records? This will allow them to be re-migrated.', 'bridge')) . '\')">';
            echo '<i class="ti ti-player-skip-forward me-1"></i>' . self::h(__('Retry failed records', 'bridge'));
            echo '</button>';
        }
        // Rollback: only for finished jobs that have not already been rolled back
        $createdCount = (int) ($stats['created'] ?? 0);
        if ($isFinished && !$isRolledBack) {
            $rollbackMsg = self::h(sprintf(
                __('This will permanently delete the %d item(s) created by this job from GLPI and allow re-migration. Continue?', 'bridge'),
                $createdCount
            ));
            echo '<button class="btn btn-sm btn-outline-danger ms-auto" onclick="bridgeJobAction(\'rollback\', \'' . $rollbackMsg . '\')">';
            echo '<i class="ti ti-rotate-clockwise me-1"></i>' . self::h(__('Rollback', 'bridge'));
            echo '</button>';
        }
        echo '</div>';

        echo '</div></div>'; // card

        // ── Cron notice ───────────────────────────────────────────────────
        if (!$isFinished) {
            $cronOk      = self::isCronHealthy($job->resourceType());
            $slotName    = BridgeJob::cronSlotName($job->resourceType());
            $alertClass  = $cronOk ? 'alert-light border' : 'alert-warning';
            $alertIcon   = $cronOk ? 'ti-info-circle text-primary' : 'ti-alert-triangle text-warning';
            echo '<div class="alert ' . $alertClass . ' small">';
            echo '<i class="ti ' . $alertIcon . ' me-1"></i>';
            if ($cronOk) {
                echo self::h(__('The migration runs via the GLPI scheduler.', 'bridge'));
                echo ' <span class="text-muted ms-1">(' . self::h($slotName) . ')</span>';
                echo ' ' . self::h(__('It will start within 60 seconds and process chunks automatically until complete.', 'bridge'));
            } else {
                echo '<strong>' . self::h(__('GLPI scheduler may not be running.', 'bridge')) . '</strong> ';
                echo self::h(sprintf(__('The job is queued but will not start until the %s scheduler slot runs.', 'bridge'), $slotName));
                echo ' <a href="' . self::h(\CronTask::getSearchURL(false)) . '" target="_blank" class="alert-link">';
                echo self::h(__('Check Automatic Actions', 'bridge')) . '</a>.';
            }
            echo '</div>';
        }

        // ── Recent migrations (live feed) ────────────────────────────────
        echo '<div class="card border-0 shadow-sm mb-3">';
        echo '<div class="card-header bg-light border-0 d-flex align-items-center justify-content-between py-2">';
        echo '<span class="fw-semibold small"><i class="ti ti-list-check me-1"></i>';
        echo self::h(__('Recent migrations', 'bridge')) . '</span>';
        echo '<span class="badge bg-secondary" id="bridge-recent-count">0</span>';
        echo '</div>';
        echo '<div class="table-responsive" style="max-height:380px;overflow-y:auto">';
        echo '<table class="table table-sm table-hover mb-0" style="font-size:.78rem">';
        echo '<thead class="table-light sticky-top"><tr>';
        echo '<th class="text-muted fw-normal">' . self::h(__('Time', 'bridge')) . '</th>';
        echo '<th class="text-muted fw-normal">#SW</th>';
        echo '<th class="fw-normal">' . self::h(__('Name', 'bridge')) . '</th>';
        echo '<th class="text-muted fw-normal">' . self::h(__('Status', 'bridge')) . '</th>';
        echo '<th class="fw-normal">GLPI</th>';
        echo '</tr></thead>';
        echo '<tbody id="bridge-recent-tbody">';
        echo '<tr><td colspan="5" class="text-center text-muted py-3">';
        echo '<i class="ti ti-clock me-1"></i>' . self::h(__('Waiting for the first batch to complete…', 'bridge'));
        echo '</td></tr>';
        echo '</tbody></table></div></div>';

        // ── Operational logs ──────────────────────────────────────────────
        echo '<details id="bridge-log-details" class="mb-3">';
        echo '<summary class="text-muted small" style="cursor:pointer;user-select:none">';
        echo '<i class="ti ti-terminal me-1"></i>';
        echo self::h(__('Operational logs', 'bridge'));
        echo ' <span class="badge bg-secondary ms-1" id="bridge-log-count">0</span>';
        echo '</summary>';
        echo '<div class="mt-2 table-responsive">';
        echo '<table class="table table-sm table-hover mb-0" style="font-size:.78rem">';
        echo '<thead class="table-light"><tr>';
        echo '<th class="text-muted fw-normal">#</th>';
        echo '<th class="text-muted fw-normal">' . self::h(__('Time', 'bridge')) . '</th>';
        echo '<th class="text-muted fw-normal">' . self::h(__('Pages', 'bridge')) . '</th>';
        echo '<th class="text-muted fw-normal">' . self::h(__('Scanned', 'bridge')) . '</th>';
        echo '<th class="text-muted fw-normal text-success">' . self::h(__('Created', 'bridge')) . '</th>';
        echo '<th class="text-muted fw-normal text-danger">' . self::h(__('Failed', 'bridge')) . '</th>';
        echo '<th class="text-muted fw-normal">' . self::h(__('Duration', 'bridge')) . '</th>';
        echo '<th class="text-muted fw-normal">' . self::h(__('Cursor page', 'bridge')) . '</th>';
        echo '</tr></thead>';
        echo '<tbody id="bridge-log-tbody"><tr><td colspan="8" class="text-center text-muted py-2">';
        echo self::h(__('No log entries yet.', 'bridge')) . '</td></tr></tbody>';
        echo '</table></div></details>';

        echo '</div>'; // container

        // ── JS polling ────────────────────────────────────────────────────
        $statusClasses = json_encode([
            BridgeJob::STATUS_PENDING      => 'bg-secondary',
            BridgeJob::STATUS_RUNNING      => 'bg-primary',
            BridgeJob::STATUS_COMPLETED    => 'bg-success',
            BridgeJob::STATUS_FAILED       => 'bg-danger',
            BridgeJob::STATUS_CANCELLED    => 'bg-warning text-dark',
            BridgeJob::STATUS_ROLLED_BACK  => 'bg-dark',
        ]);
        $statusLabels = json_encode([
            BridgeJob::STATUS_PENDING      => __('Pending', 'bridge'),
            BridgeJob::STATUS_RUNNING      => __('Running…', 'bridge'),
            BridgeJob::STATUS_COMPLETED    => __('Completed', 'bridge'),
            BridgeJob::STATUS_FAILED       => __('Failed', 'bridge'),
            BridgeJob::STATUS_CANCELLED    => __('Cancelled', 'bridge'),
            BridgeJob::STATUS_ROLLED_BACK  => __('Rolled back', 'bridge'),
        ]);

        $initiallyFinished = $isFinished ? 'true' : 'false';
        $initialPayload = json_encode(
            BridgeJob::getStatusPayload($jobId, true, true),
            JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT
        );
        $csrfToken = self::h(\Session::getNewCSRFToken());

        echo <<<JS
<script>
(function () {
    var jobId      = {$jobId};
    var ajaxUrl    = '{$ajaxUrl}';
    var csrfToken  = '{$csrfToken}';
    var finished   = {$initiallyFinished};
    var classes    = {$statusClasses};
    var labels     = {$statusLabels};
    var pollTimer  = null;
    var initialData = {$initialPayload};

    function update(data, clearActionsOnFinish) {
        if (data.error) return;
        if (clearActionsOnFinish === undefined) clearActionsOnFinish = true;

        // Badge
        var badge = document.getElementById('bridge-status-badge');
        badge.className = 'badge fs-6 ' + (classes[data.status] || 'bg-secondary');
        badge.textContent = labels[data.status] || data.status;

        // Stats
        var s = data.stats || {};
        ['created','failed','skipped','scanned'].forEach(function(k) {
            var el = document.getElementById('bridge-stat-' + k);
            if (el) el.textContent = s[k] || 0;
        });

        var counters = [
            'api_pages','queued','duplicates','comments_read',
            'followups_created','attachments_downloaded',
            'attachments_failed','documents_linked'
        ];
        counters.forEach(function(k) {
            var el = document.getElementById('bridge-metric-' + k);
            if (el) el.textContent = s[k] || 0;
        });

        var timings = {
            api: 'time_api_ms',
            dedupe: 'time_dedupe_ms',
            map: 'time_map_ms',
            comments: 'time_comments_ms',
            ticket_create: 'time_ticket_create_ms',
            followups: 'time_followups_ms',
            attachments: 'time_attachments_ms',
            total: 'duration_ms_total'
        };
        Object.keys(timings).forEach(function(label) {
            var el = document.getElementById('bridge-time-' + label);
            if (el) el.textContent = formatMs(s[timings[label]] || 0);
        });

        // Error
        var errEl = document.getElementById('bridge-error-msg');
        if (data.error_message) {
            errEl.style.display = '';
            errEl.textContent = data.error_message;
        } else {
            errEl.style.display = 'none';
        }

        // Heartbeat
        if (data.last_heartbeat) {
            document.getElementById('bridge-heartbeat').textContent = 'Last update: ' + data.last_heartbeat;
        }

        // Spinner
        var spinner = document.getElementById('bridge-job-spinner');
        var running = data.status === 'running' || data.status === 'pending';
        spinner.className = running ? 'ti ti-loader-2 me-2 text-primary bridge-spin' : 'ti ti-database-import me-2 text-muted';

        // Stop polling when done
        if (['completed','failed','cancelled','rolled_back'].includes(data.status)) {
            if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
            if (clearActionsOnFinish) {
                document.getElementById('bridge-job-actions').innerHTML = '';
            }
            finished = true;
        }
    }

    var statusColors = {
        'success': 'bg-success',
        'failed':  'bg-danger',
        'skipped': 'bg-secondary',
        'warning': 'bg-warning text-dark',
    };

    function renderRecent(records) {
        if (!records) return;
        var tbody = document.getElementById('bridge-recent-tbody');
        var count = document.getElementById('bridge-recent-count');
        if (!tbody) return;
        count.textContent = records.length;
        if (!records.length) return;
        tbody.innerHTML = records.map(function(r) {
            var status = r.status || 'skipped';
            var badge  = '<span class="badge ' + (statusColors[status] || 'bg-secondary') + '">' + status + '</span>';
            var name   = (r.name || '').substring(0, 55);
            var glpi   = r.glpi_url && r.tickets_id > 0
                ? '<a href="' + r.glpi_url + '" target="_blank" class="text-decoration-none">#' + r.tickets_id + ' <i class="ti ti-external-link" style="font-size:.7rem"></i></a>'
                : '<span class="text-muted">—</span>';
            var time   = (r.migrated_at || '').substring(11, 19);
            var row    = '<tr>'
                + '<td class="text-muted">' + time + '</td>'
                + '<td class="text-muted font-monospace">' + (r.source_number || '') + '</td>'
                + '<td>' + name.replace(/</g,'&lt;') + '</td>'
                + '<td>' + badge + '</td>'
                + '<td>' + glpi + '</td>'
                + '</tr>';
            if (r.error_message) {
                row += '<tr><td colspan="5" class="text-danger small px-3 pb-1">↳ ' + r.error_message.substring(0, 120).replace(/</g,'&lt;') + '</td></tr>';
            }
            return row;
        }).join('');
    }

    function renderLogs(logs) {
        if (!logs || !logs.length) return;
        var tbody = document.getElementById('bridge-log-tbody');
        document.getElementById('bridge-log-count').textContent = logs.length;
        tbody.innerHTML = logs.map(function(l) {
            var err = '';
            if (l.errors_json) {
                try {
                    var errs = JSON.parse(l.errors_json);
                    if (errs.length) err = '<div class="text-danger mt-1 small">' + errs.slice(0,3).map(function(e){ return '• ' + e; }).join('<br>') + '</div>';
                } catch(e) {}
            }
            var metrics = '';
            if (l.metrics_json) {
                try {
                    var m = JSON.parse(l.metrics_json);
                    metrics = '<div class="text-muted mt-1 small">API ' + formatMs(m.time_api_ms || 0)
                        + ' · map ' + formatMs(m.time_map_ms || 0)
                        + ' · create ' + formatMs(m.time_ticket_create_ms || 0)
                        + ' · comments ' + (m.comments_read || 0)
                        + ' · attachments ' + (m.attachments_downloaded || 0) + '/' + (m.attachments_detected || 0)
                        + '</div>';
                } catch(e) {}
            }
            return '<tr>' +
                '<td class="text-muted">' + l.chunk + '</td>' +
                '<td class="text-muted">' + (l.logged_at || '').substring(11,19) + '</td>' +
                '<td>' + l.pages_read + '</td>' +
                '<td>' + l.scanned + '</td>' +
                '<td class="text-success">' + l.created + '</td>' +
                '<td class="text-danger">' + l.failed + '</td>' +
                '<td>' + (l.duration_ms > 999 ? (l.duration_ms/1000).toFixed(1) + 's' : l.duration_ms + 'ms') + '</td>' +
                '<td>' + (l.cursor_page || '—') + '</td>' +
                '</tr>' + (err || metrics ? '<tr><td colspan="8">' + metrics + err + '</td></tr>' : '');
        }).join('');
    }

    function formatMs(ms) {
        ms = Number(ms || 0);
        if (ms >= 1000) return (ms / 1000).toFixed(ms >= 10000 ? 0 : 1) + 's';
        return ms + 'ms';
    }

    function poll() {
        if (finished) return;
        fetch(ajaxUrl + '?job_id=' + jobId + '&logs=1&recent=1', { credentials: 'same-origin' })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                update(data);
                renderRecent(data.recent);
                renderLogs(data.logs);
            })
            .catch(function() {});
    }

    window.bridgeJobAction = function(action, confirmMsg) {
        if (!confirm(confirmMsg)) return;
        var fd = new FormData();
        fd.append(action, '1');
        fd.append('_glpi_csrf_token', csrfToken);
        fetch(ajaxUrl + '?job_id=' + jobId, {
            method: 'POST',
            body: fd,
            credentials: 'same-origin',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'X-Glpi-Csrf-Token': csrfToken
            }
        })
            .then(function(r) {
                return r.text().then(function(text) {
                    var data = text ? JSON.parse(text) : {};
                    if (!r.ok) {
                        throw new Error(data.error || data.message || ('HTTP ' + r.status));
                    }
                    return data;
                });
            })
            .then(function(data) {
                if (data.redirected_job_id) {
                    // Retry created a new job — navigate to it
                    window.location.href = window.location.pathname + '?job_id=' + data.redirected_job_id;
                    return;
                }
                if (data.purged_records !== undefined) {
                    var msg = data.purged_records + ' failed record(s) purged. Run a new migration to retry them.';
                    alert(msg);
                    return;
                }
                if (data.rollback_result !== undefined) {
                    var r = data.rollback_result;
                    alert('Rollback complete.\nDeleted: ' + r.purged + ' of ' + r.total + ' items.' +
                        (r.failed > 0 ? '\nFailed to delete: ' + r.failed : '') +
                        (r.not_found > 0 ? '\nNot found (already removed): ' + r.not_found : '') +
                        '\nMigration records cleared — items can be re-migrated.');
                    window.location.reload();
                    return;
                }
                update(data);
            })
            .catch(function(error) {
                alert(error.message || 'Job action failed.');
            });
    };

    update(initialData, false);
    renderRecent(initialData.recent);
    renderLogs(initialData.logs);

    if (!finished) {
        poll();
        pollTimer = setInterval(poll, 4000);
    }
})();
</script>
<style>
@keyframes bridge-spin { to { transform: rotate(360deg); } }
.bridge-spin { display: inline-block; animation: bridge-spin 1s linear infinite; }
</style>
JS;
    }

    /**
     * Returns true when any Bridge cron slot has run within the last 5 minutes.
     * Checks the typed slot for $resourceType first (Etapa 2), then all others
     * as a fallback so the notice is correct even when slot names change.
     * Also accepts the legacy 'bridge' itemtype for installations that have not
     * yet re-installed the plugin since the GLPI 11 PSR-4 migration.
     */
    private static function isCronHealthy(string $resourceType = ''): bool
    {
        global $DB;

        // Prefer the typed slot; include all Bridge slots as fallback
        $preferredSlot = BridgeJob::cronSlotName($resourceType);
        $allSlots = [$preferredSlot, 'ProcessIncidents', 'ProcessChanges', 'ProcessProblems', 'ProcessJobs'];
        $cutoff   = date('Y-m-d H:i:s', strtotime('-5 minutes'));

        foreach ($DB->request([
            'SELECT' => ['lastrun'],
            'FROM'   => 'glpi_crontasks',
            'WHERE'  => [
                'OR' => [
                    ['itemtype' => BridgeJob::class, 'name' => $allSlots],
                    ['itemtype' => 'bridge',          'name' => $allSlots],
                ],
            ],
        ]) as $row) {
            if (!empty($row['lastrun']) && $row['lastrun'] > $cutoff) {
                return true;
            }
        }
        return false;
    }

    private static function statBox(string $icon, string $color, int $value, string $label, string $id): void
    {
        echo '<div class="col-6 col-md-3">';
        echo '<div class="border rounded p-2 text-center">';
        echo '<div class="fw-bold fs-4 text-' . $color . '" id="' . $id . '">' . $value . '</div>';
        echo '<div class="text-muted small"><i class="ti ti-' . $icon . ' me-1"></i>' . self::h($label) . '</div>';
        echo '</div></div>';
    }

    private static function metricPill(string $label, string $id): void
    {
        echo '<div class="col-6 col-md-3">';
        echo '<div class="d-flex align-items-center justify-content-between gap-2 border rounded px-2 py-1 bg-white">';
        echo '<span class="text-muted text-truncate">' . self::h($label) . '</span>';
        echo '<strong class="font-monospace" id="' . self::h($id) . '">0</strong>';
        echo '</div></div>';
    }

    private static function statusClass(string $status): string
    {
        return match ($status) {
            BridgeJob::STATUS_PENDING      => 'bg-secondary',
            BridgeJob::STATUS_RUNNING      => 'bg-primary',
            BridgeJob::STATUS_COMPLETED    => 'bg-success',
            BridgeJob::STATUS_FAILED       => 'bg-danger',
            BridgeJob::STATUS_CANCELLED    => 'bg-warning text-dark',
            BridgeJob::STATUS_ROLLED_BACK  => 'bg-dark',
            default                        => 'bg-secondary',
        };
    }

    private static function statusLabel(string $status): string
    {
        return match ($status) {
            BridgeJob::STATUS_PENDING      => __('Pending', 'bridge'),
            BridgeJob::STATUS_RUNNING      => __('Running…', 'bridge'),
            BridgeJob::STATUS_COMPLETED    => __('Completed', 'bridge'),
            BridgeJob::STATUS_FAILED       => __('Failed', 'bridge'),
            BridgeJob::STATUS_CANCELLED    => __('Cancelled', 'bridge'),
            BridgeJob::STATUS_ROLLED_BACK  => __('Rolled back', 'bridge'),
            default                        => $status,
        };
    }

    private static function h(mixed $v): string
    {
        return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    }
}

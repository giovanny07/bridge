<?php

namespace GlpiPlugin\Bridge\Page;

use GlpiPlugin\Bridge\Connection;
use GlpiPlugin\Bridge\Migration\MigrationCursor;
use GlpiPlugin\Bridge\Migration\MigrationResult;

class MigratePage
{
    public static function showSelector(
        Connection $connection,
        array $resourceTypes,
        string $migrateUrl,
        string $historyUrl
    ): void {
        $id = (int) $connection->fields['id'];

        echo '<div class="container-fluid p-3" style="max-width:640px">';
        echo '<div class="d-flex align-items-center justify-content-between mb-3">';
        echo '<h4 class="m-0"><i class="ti ti-database-import me-2 text-primary"></i>';
        echo self::h(__('Migration', 'bridge')) . '</h4>';
        echo '<div class="d-flex gap-2">';
        echo '<a class="btn btn-outline-secondary btn-sm" href="' . self::h($historyUrl . '?id=' . $id) . '">';
        echo '<i class="ti ti-history me-1"></i>' . self::h(__('History', 'bridge')) . '</a>';
        echo '<a class="btn btn-outline-secondary btn-sm" href="' . self::h(Connection::getConfigURL($id)) . '">';
        echo '<i class="ti ti-arrow-left me-1"></i>' . self::h(__('Back', 'bridge')) . '</a>';
        echo '</div>';
        echo '</div>';

        echo '<div class="card">';
        echo '<div class="card-header fw-semibold">';
        echo '<i class="ti ti-plug me-1"></i>' . self::h($connection->fields['name']);
        echo '</div>';
        echo '<div class="card-body">';
        echo '<p class="text-muted mb-3">' . self::h(__('Choose the resource type to migrate.', 'bridge')) . '</p>';

        foreach ($resourceTypes as $key => $meta) {
            $implemented = (bool) ($meta['implemented'] ?? false);
            $label       = (string) ($meta['label'] ?? $key);

            echo '<div class="mb-2">';
            if ($implemented) {
                $url = $migrateUrl . '?id=' . $id . '&resource_type=' . rawurlencode($key);
                echo '<a class="btn btn-outline-primary w-100 text-start" href="' . self::h($url) . '">';
                echo '<i class="ti ti-arrow-right me-2"></i>' . self::h($label);
                echo '</a>';
            } else {
                echo '<div class="btn btn-outline-secondary w-100 text-start disabled d-flex justify-content-between align-items-center">';
                echo '<span><i class="ti ti-lock me-2 text-muted"></i>' . self::h($label) . '</span>';
                echo '<span class="badge bg-secondary">' . self::h(__('Not implemented yet', 'bridge')) . '</span>';
                echo '</div>';
            }
            echo '</div>';
        }

        echo '</div>';
        echo '</div>';
        echo '</div>';
    }

    public static function showForm(
        Connection $connection,
        array      $resourceTypes,
        string     $migrateUrl,
        string     $historyUrl,
        string     $resourceType = 'incidents'
    ): void {
        $id  = (int) $connection->fields['id'];
        $key = 'bridge_form_' . $id; // sessionStorage key per connection
        $resourceLabel = (string) ($resourceTypes[$resourceType]['label'] ?? ucfirst($resourceType));

        echo '<div class="container-fluid py-3 px-4" style="max-width:780px">';

        // ── Header ───────────────────────────────────────────────────────
        echo '<div class="d-flex align-items-center justify-content-between mb-4">';
        echo '<div>';
        echo '<h4 class="mb-0"><i class="ti ti-database-import me-2 text-primary"></i>' . self::h(__('Migration', 'bridge')) . '</h4>';
        echo '<div class="text-muted small mt-1"><i class="ti ti-plug me-1"></i><strong>' . self::h($connection->fields['name']) . '</strong>';
        echo ' &mdash; <span class="badge bg-primary me-1">' . self::h($resourceLabel) . '</span>';
        echo self::h($connection->fields['base_url'] ?? '') . '</div>';
        echo '</div>';
        echo '<div class="d-flex gap-2">';
        echo '<a class="btn btn-sm btn-outline-secondary" href="' . self::h($migrateUrl . '?id=' . $id) . '"><i class="ti ti-switch-horizontal me-1"></i>' . self::h(__('Change type', 'bridge')) . '</a>';
        echo '<a class="btn btn-sm btn-outline-secondary" href="' . self::h($historyUrl . '?id=' . $id) . '"><i class="ti ti-history me-1"></i>' . self::h(__('History', 'bridge')) . '</a>';
        echo '<a class="btn btn-sm btn-outline-secondary" href="' . self::h(Connection::getConfigURL($id)) . '"><i class="ti ti-arrow-left me-1"></i>' . self::h(__('Back', 'bridge')) . '</a>';
        echo '</div>';
        echo '</div>';

        echo '<form method="post" action="' . self::h($migrateUrl) . '" id="bridge-migrate-form" data-storage-key="' . self::h($key) . '">';
        echo \Html::hidden('_glpi_csrf_token', ['value' => \Session::getNewCSRFToken()]);
        echo \Html::hidden('id', ['value' => $id]);
        echo \Html::hidden('resource_type', ['value' => $resourceType]);

        // ── Migration mode ───────────────────────────────────────────────
        echo self::sectionCard('ti-adjustments-horizontal', __('Mode', 'bridge'));
        echo '<div class="d-flex gap-2 mb-3" role="group">';
        echo '<button type="button" id="btn-mode-filters" class="btn btn-sm bridge-mode-btn active" data-bridge-mode="filters">';
        echo '<i class="ti ti-filter me-1"></i>' . self::h(__('By filters', 'bridge'));
        echo '</button>';
        echo '<button type="button" id="btn-mode-ids" class="btn btn-sm bridge-mode-btn" data-bridge-mode="ids">';
        echo '<i class="ti ti-hash me-1"></i>' . self::h(__('By source IDs', 'bridge'));
        echo '</button>';
        echo '</div>';
        echo '<input type="hidden" name="migration_mode" id="migration_mode_val" value="filters">';

        // ── Mode: By filters ─────────────────────────────────────────────
        echo '<div id="bridge_section_filters">';

        // State — visual pills
        echo '<div class="mb-3">';
        echo '<label class="form-label fw-medium d-block mb-1">' . self::h(__('Status', 'bridge')) . '</label>';
        $states = [
            ''                         => [__('All',            'bridge'), 'secondary'],
            'Closed'                   => ['Closed',                       'success'],
            'Solucionado'              => ['Solucionado',                  'success'],
            'En Proceso'               => ['En Proceso',                   'primary'],
            'Pending Assignment'       => ['Pending Assignment',           'warning'],
            'Pendiente Acción Cliente' => ['Pendiente Acción Cliente',     'warning'],
            'Gestión Proveedor'        => ['Gestión Proveedor',            'info'],
        ];
        echo '<div class="d-flex flex-wrap gap-1">';
        $first = true;
        foreach ($states as $val => [$lbl, $color]) {
            $checked = $first ? ' checked' : '';
            echo '<label class="bridge-state-pill" data-color="' . $color . '">';
            echo '<input type="radio" name="state" id="f_state_' . self::h($val ?: 'all') . '" value="' . self::h($val) . '"' . $checked . '>';
            echo self::h($lbl);
            echo '</label>';
            $first = false;
        }
        echo '</div>';
        echo '</div>';

        // ── Time period ───────────────────────────────────────────────────
        echo '<div class="mb-3">';
        echo '<label class="form-label fw-medium d-block mb-2">' . self::h(__('Time period', 'bridge')) . '</label>';

        $periods = [
            'recent'      => ['ti-clock',        __('Recent',      'bridge'), __('Latest tickets — starts from today backward.',       'bridge'), ''],
            'from_date'   => ['ti-calendar-plus', __('From date',   'bridge'), __('Tickets created on or after a specific date.',       'bridge'), ''],
            'incremental' => ['ti-refresh',       __('Incremental', 'bridge'), __('Only tickets modified since a date (for re-runs).',  'bridge'), ''],
            'manual'      => ['ti-adjustments',   __('Manual',      'bridge'), __('Specify the starting page number directly.',         'bridge'), ''],
        ];

        foreach ($periods as $pval => [$icon, $plbl, $pdesc, $_]) {
            $checked = $pval === 'recent' ? ' checked' : '';
            echo '<label class="bridge-period-option' . ($pval === 'recent' ? ' active' : '') . '" id="period-label-' . $pval . '">';
            echo '<div class="d-flex align-items-start gap-3">';
            echo '<input type="radio" name="time_period" value="' . $pval . '"' . $checked . ' class="form-check-input mt-1 flex-shrink-0">';
            echo '<div>';
            echo '<div class="fw-medium"><i class="ti ' . $icon . ' me-1"></i>' . self::h($plbl) . '</div>';
            echo '<div class="text-muted small">' . self::h($pdesc) . '</div>';

            if ($pval === 'from_date') {
                echo '<div class="bridge-period-extra mt-2" style="display:none">';
                echo '<input type="date" class="form-control form-control-sm" style="max-width:220px" name="created_after" id="f_created_after">';
                echo '</div>';
            } elseif ($pval === 'incremental') {
                echo '<div class="bridge-period-extra mt-2" style="display:none">';
                echo '<input type="date" class="form-control form-control-sm" style="max-width:220px" name="updated_after" id="f_updated_after">';
                echo '</div>';
            } elseif ($pval === 'manual') {
                echo '<div class="bridge-period-extra mt-2" style="display:none">';
                echo '<div class="d-flex align-items-center gap-2">';
                echo '<label class="small text-muted mb-0">' . self::h(__('Start page:', 'bridge')) . '</label>';
                echo '<input type="number" class="form-control form-control-sm" style="width:100px" name="start_page" id="f_start_page" value="1" min="1">';
                echo '</div>';
                echo '</div>';
            }

            echo '</div></div></label>';
        }
        echo '</div>';

        // Limit — always visible
        echo '<div class="d-flex align-items-center gap-3 pt-1">';
        echo '<label class="form-label fw-medium mb-0 text-nowrap">' . self::h(__('Max per run', 'bridge')) . '</label>';
        echo '<input type="number" class="form-control form-control-sm" style="width:100px" name="limit" id="f_limit" value="50" min="1" max="500">';
        echo '<span class="text-muted small">' . self::h(__('tickets', 'bridge')) . '</span>';
        echo '</div>';

        echo '</div>'; // bridge_section_filters

        // ── Mode: By IDs ─────────────────────────────────────────────────
        echo '<div id="bridge_section_ids" style="display:none">';
        echo '<label class="form-label fw-medium">' . self::h(__('Ticket numbers or source IDs', 'bridge')) . '</label>';
        echo '<input type="text" class="form-control form-control-sm font-monospace" name="source_ids" id="f_source_ids" placeholder="#194943, #194944  or  182566420, 182566421" autocomplete="off">';
        echo '<div class="form-text">';
        echo '<i class="ti ti-hash me-1"></i>' . self::h(__('Use the ticket number (e.g. #194943) or the internal SW ID. Mixed lists are supported.', 'bridge'));
        echo '</div>';
        echo '</div>';

        echo '</div>'; // card

        // ── Content options ──────────────────────────────────────────────
        echo self::sectionCard('ti-settings-2', __('Content', 'bridge'));
        echo '<div class="row g-3">';

        echo '<div class="col-md-6">';
        echo '<label class="form-label fw-medium">' . self::h(__('Default requester', 'bridge')) . '</label>';
        \User::dropdown([
            'name'  => 'default_requesters_id',
            'value' => 0,
            'width' => '100%',
            'right' => 'all',   // show all active users, not just those with entity profiles
        ]);
        echo '<div class="form-text">' . self::h(__('Used when source has no requester email.', 'bridge')) . '</div>';
        echo '</div>';

        echo '<div class="col-md-6 d-flex flex-column justify-content-center gap-2 pt-2">';
        echo self::checkbox('inc_comments',   'include_comments',       __('Comments → Followups', 'bridge'),           'ti-message',   true);
        echo self::checkbox('inc_attachments','include_attachments',    __('Attachments → Documents', 'bridge') . ' <span class="text-muted small">(' . self::h(__('slower', 'bridge')) . ')</span>', 'ti-paperclip', true);
        echo self::checkbox('keep_private',   'keep_private_comments',  __('Preserve private flag on comments', 'bridge'), 'ti-lock',    true);
        echo '</div>';

        echo '</div>'; // row
        echo '</div>'; // card

        // ── Action buttons ───────────────────────────────────────────────
        echo '<div class="d-flex flex-wrap align-items-center gap-3 mt-2">';
        echo '<div>';
        echo '<button type="submit" name="action" value="migrate" class="btn btn-primary">';
        echo '<i class="ti ti-player-play me-1"></i>' . self::h(__('Start migration', 'bridge'));
        echo '</button>';
        echo '<div class="text-muted" style="font-size:.73rem;margin-top:.2rem">';
        echo '<i class="ti ti-clock me-1"></i>' . self::h(__('Creates a background job — progress visible on the next screen.', 'bridge'));
        echo '</div>';
        echo '</div>';
        echo '<button type="submit" name="action" value="dryrun" class="btn btn-outline-warning">';
        echo '<i class="ti ti-list-check me-1"></i>' . self::h(__('Dry-run preview', 'bridge'));
        echo '</button>';
        echo '</div>';

        echo '</form>';

        echo '</div>';
    }

    public static function showResult(
        Connection       $connection,
        MigrationResult  $result,
        string           $resourceType,
        string           $historyUrl,
        ?\GlpiPlugin\Bridge\Migration\MigrationCursor $cursor = null
    ): void {
        $id    = (int) $connection->fields['id'];
        $isDry = $result->isDryRun;

        $cursorActive = $cursor !== null && $cursor->isActive();
        $title = $isDry
            ? __('Dry-run preview', 'bridge')
            : ($cursorActive ? __('Batch complete — more pages ahead', 'bridge') : __('Migration complete', 'bridge'));
        $icon  = $isDry ? 'list-check text-warning'
            : ($cursorActive ? 'player-skip-forward text-primary' : 'circle-check text-success');

        echo '<div class="container-fluid py-3 px-4" style="max-width:860px">';

        // Header
        echo '<div class="d-flex align-items-center justify-content-between mb-4">';
        echo '<h4 class="mb-0"><i class="ti ti-' . $icon . ' me-2"></i>' . self::h($title) . '</h4>';
        echo '<div class="d-flex gap-2">';
        if (!$isDry) {
            echo '<a class="btn btn-sm btn-outline-secondary" href="' . self::h($historyUrl . '?id=' . $id) . '"><i class="ti ti-history me-1"></i>' . self::h(__('History', 'bridge')) . '</a>';
        }
        echo '<a class="btn btn-sm btn-outline-secondary" href="' . self::h(Connection::getConfigURL($id)) . '"><i class="ti ti-arrow-left me-1"></i>' . self::h(__('Back', 'bridge')) . '</a>';
        echo '</div>';
        echo '</div>';

        // Summary cards
        echo '<div class="row g-3 mb-4">';
        self::statCard('circle-check', 'success',   count($result->created), $isDry ? __('Would create', 'bridge') : __('Created', 'bridge'));
        self::statCard('circle-x',     'danger',     count($result->failed),  __('Failed', 'bridge'));
        self::statCard('minus-circle', 'secondary',  count($result->skipped), __('Skipped', 'bridge'));
        echo '</div>';

        if ($isDry) {
            echo '<div class="alert alert-warning d-flex align-items-center gap-2">';
            echo '<i class="ti ti-alert-triangle fs-5"></i>';
            echo '<span>' . self::h(__('Dry-run — nothing was written to GLPI.', 'bridge')) . '</span>';
            echo '</div>';
        }

        // ── Cursor progress banner ────────────────────────────────────────
        if ($cursor !== null && !$isDry) {
            $migrateUrl = Connection::getPluginBaseURL() . '/front/migrate.php';
            if ($cursor->isActive()) {
                echo '<div class="alert alert-primary d-flex align-items-center justify-content-between gap-3 mb-3">';
                echo '<div>';
                echo '<i class="ti ti-player-skip-forward me-1"></i>';
                echo '<strong>' . self::h(__('Batch complete', 'bridge')) . '</strong> — ';
                echo sprintf(
                    self::h(__('resuming at page %d · %d migrated so far across all runs', 'bridge')),
                    $cursor->currentPage(),
                    $cursor->createdTotal()
                );
                echo '</div>';
                echo '<div class="d-flex gap-2 flex-shrink-0">';
                // Continue form — resubmits the same resource_type so the form re-runs with the stored cursor
                echo '<form method="post" action="' . self::h($migrateUrl) . '" class="d-inline">';
                echo \Html::hidden('_glpi_csrf_token', ['value' => \Session::getNewCSRFToken()]);
                echo \Html::hidden('id',            ['value' => $id]);
                echo \Html::hidden('action',        ['value' => 'migrate']);
                echo \Html::hidden('resource_type', ['value' => $resourceType]);
                // Reuse cursor options (state, created_after, limit, etc.)
                foreach ($cursor->optionsJson() as $k => $v) {
                    if (is_scalar($v)) {
                        echo \Html::hidden($k, ['value' => (string) $v]);
                    }
                }
                $continueConfirm = addslashes(self::h(__('This will start a real migration (not a dry-run). Continue?', 'bridge')));
                echo '<button type="submit" class="btn btn-sm btn-primary" onclick="return confirm(\'' . $continueConfirm . '\')">';
                echo '<i class="ti ti-player-play me-1"></i>' . self::h(__('Start real migration', 'bridge'));
                echo '</button></form>';
                // Reset cursor
                echo '<form method="post" action="' . self::h($migrateUrl) . '" class="d-inline">';
                echo \Html::hidden('_glpi_csrf_token', ['value' => \Session::getNewCSRFToken()]);
                echo \Html::hidden('id',            ['value' => $id]);
                echo \Html::hidden('action',        ['value' => 'migrate']);
                echo \Html::hidden('resource_type', ['value' => $resourceType]);
                echo \Html::hidden('reset_cursor',  ['value' => '1']);
                foreach ($cursor->optionsJson() as $k => $v) {
                    if (is_scalar($v)) {
                        echo \Html::hidden($k, ['value' => (string) $v]);
                    }
                }
                echo '<button type="submit" class="btn btn-sm btn-outline-secondary">';
                echo '<i class="ti ti-refresh me-1"></i>' . self::h(__('Reset', 'bridge'));
                echo '</button></form>';
                echo '</div>';
                echo '</div>';
            } else {
                // Completed
                echo '<div class="alert alert-success d-flex align-items-center gap-2 mb-3">';
                echo '<i class="ti ti-circle-check fs-5"></i>';
                echo '<span>' . sprintf(
                    self::h(__('All pages scanned. %d records migrated in total across all runs.', 'bridge')),
                    $cursor->createdTotal()
                ) . '</span>';
                echo '</div>';
            }
        }

        if (!empty($result->stats)) {
            echo '<div class="card mb-3 border-0 shadow-sm">';
            echo '<div class="card-header bg-light border-0 fw-semibold py-2">';
            echo '<i class="ti ti-route me-1"></i>' . self::h(__('Pipeline', 'bridge'));
            echo '</div>';
            echo '<div class="card-body py-2">';
            echo '<div class="d-flex flex-wrap gap-2 small">';
            self::metricPill(__('API pages', 'bridge'), (int) ($result->stats['api_pages'] ?? 0));
            self::metricPill(__('Scanned', 'bridge'), (int) ($result->stats['scanned'] ?? 0));
            self::metricPill(__('Date matched', 'bridge'), (int) ($result->stats['date_matched'] ?? 0));
            self::metricPill(__('Duplicates', 'bridge'), (int) ($result->stats['duplicates'] ?? 0));
            self::metricPill(__('Queued', 'bridge'), (int) ($result->stats['queued'] ?? 0));
            self::metricPill(__('Comment calls', 'bridge'), (int) ($result->stats['comments_requests'] ?? 0));
            echo '</div>';
            echo '</div>';
            echo '</div>';
        }

        // Created
        if (!empty($result->created)) {
            echo '<div class="card mb-3 border-0 shadow-sm">';
            echo '<div class="card-header bg-success bg-opacity-10 border-0 fw-semibold text-success py-2">';
            echo '<i class="ti ti-circle-check me-1"></i>' . self::h($isDry ? __('Would create', 'bridge') : __('Created tickets', 'bridge'));
            echo ' <span class="badge bg-success ms-1">' . count($result->created) . '</span>';
            echo '</div>';
            echo '<div class="table-responsive"><table class="table table-sm table-hover mb-0">';
            echo '<thead class="table-light"><tr>';
            echo '<th class="text-muted fw-normal small">#SW</th>';
            echo '<th class="fw-normal">' . self::h(__('Name', 'bridge')) . '</th>';
            if (!$isDry) echo '<th class="text-muted fw-normal small">GLPI</th>';
            echo '</tr></thead><tbody>';
            foreach ($result->created as $r) {
                echo '<tr>';
                echo '<td class="text-muted small">' . self::h($r['number']) . '</td>';
                echo '<td>' . self::h($r['name']) . '</td>';
                if (!$isDry) {
                    $glpiClass = match ($resourceType) {
                        'problems' => 'Problem',
                        'changes'  => 'Change',
                        default    => 'Ticket',
                    };
                    $ticketUrl = $glpiClass::getFormURLWithID((int) $r['tickets_id']);
                    echo '<td><a href="' . self::h($ticketUrl) . '" target="_blank" class="text-decoration-none">#' . (int) $r['tickets_id'] . ' <i class="ti ti-external-link" style="font-size:.75rem"></i></a></td>';
                }
                echo '</tr>';
            }
            echo '</tbody></table></div></div>';
        }

        // Failed
        if (!empty($result->failed)) {
            echo '<div class="card mb-3 border-0 shadow-sm">';
            echo '<div class="card-header bg-danger bg-opacity-10 border-0 fw-semibold text-danger py-2">';
            echo '<i class="ti ti-circle-x me-1"></i>' . self::h(__('Failed', 'bridge'));
            echo ' <span class="badge bg-danger ms-1">' . count($result->failed) . '</span>';
            echo '</div>';
            echo '<div class="table-responsive"><table class="table table-sm mb-0">';
            echo '<thead class="table-light"><tr><th class="fw-normal">#</th><th class="fw-normal">' . self::h(__('Name', 'bridge')) . '</th><th class="fw-normal">' . self::h(__('Error', 'bridge')) . '</th></tr></thead><tbody>';
            foreach ($result->failed as $r) {
                echo '<tr>';
                echo '<td class="text-muted small">' . self::h($r['number']) . '</td>';
                echo '<td>' . self::h($r['name']) . '</td>';
                echo '<td><span class="text-danger small">' . self::h($r['reason']) . '</span></td>';
                echo '</tr>';
            }
            echo '</tbody></table></div></div>';
        }

        echo '</div>';
    }

    public static function showPreflight(
        Connection $connection,
        MigrationResult $result,
        string $resourceType,
        array $resourceTypes,
        array $options,
        string $migrateUrl,
        string $historyUrl
    ): void {
        $id = (int) $connection->fields['id'];
        $resourceLabel = (string) ($resourceTypes[$resourceType]['label'] ?? ucfirst($resourceType));
        $candidates = count($result->created);
        $duplicates = count($result->skipped);
        $blocked = count($result->failed);
        $scanned = (int) ($result->stats['scanned'] ?? 0);
        $queued = (int) ($result->stats['queued'] ?? 0);

        echo '<div class="container-fluid py-3 px-4" style="max-width:900px">';
        echo '<div class="d-flex align-items-center justify-content-between mb-4">';
        echo '<div>';
        echo '<h4 class="mb-0"><i class="ti ti-shield-check me-2 text-primary"></i>' . self::h(__('Migration preflight', 'bridge')) . '</h4>';
        echo '<div class="text-muted small mt-1"><i class="ti ti-plug me-1"></i><strong>' . self::h($connection->fields['name']) . '</strong>';
        echo ' &mdash; <span class="badge bg-primary me-1">' . self::h($resourceLabel) . '</span>';
        echo self::h($connection->fields['base_url'] ?? '') . '</div>';
        echo '</div>';
        echo '<div class="d-flex gap-2">';
        echo '<a class="btn btn-sm btn-outline-secondary" href="' . self::h($historyUrl . '?id=' . $id) . '"><i class="ti ti-history me-1"></i>' . self::h(__('History', 'bridge')) . '</a>';
        echo '<a class="btn btn-sm btn-outline-secondary" href="' . self::h($migrateUrl . '?id=' . $id . '&resource_type=' . rawurlencode($resourceType)) . '"><i class="ti ti-arrow-left me-1"></i>' . self::h(__('Edit filters', 'bridge')) . '</a>';
        echo '</div>';
        echo '</div>';

        echo '<div class="alert alert-info d-flex align-items-center gap-2">';
        echo '<i class="ti ti-lock"></i>';
        echo '<span>' . self::h(__('Read-only preflight. No data was written to GLPI.', 'bridge')) . '</span>';
        echo '</div>';

        echo '<div class="row g-3 mb-3">';
        self::statCard('circle-check', 'success', $candidates, __('Candidates', 'bridge'));
        self::statCard('minus-circle', 'secondary', $duplicates, __('Already migrated', 'bridge'));
        self::statCard('circle-x', 'danger', $blocked, __('Blocked', 'bridge'));
        echo '</div>';

        echo '<div class="card mb-3 border-0 shadow-sm">';
        echo '<div class="card-header bg-light border-0 fw-semibold py-2">';
        echo '<i class="ti ti-route me-1"></i>' . self::h(__('Preflight summary', 'bridge'));
        echo '</div><div class="card-body py-2">';
        echo '<div class="d-flex flex-wrap gap-2 small">';
        self::metricPill(__('Scanned', 'bridge'), $scanned);
        self::metricPill(__('Queued', 'bridge'), $queued);
        self::metricPill(__('Date matched', 'bridge'), (int) ($result->stats['date_matched'] ?? 0));
        self::metricPill(__('Duplicates', 'bridge'), (int) ($result->stats['duplicates'] ?? 0));
        self::metricPill(__('Mapped', 'bridge'), (int) ($result->stats['mapped'] ?? 0));
        self::metricPill(__('API pages', 'bridge'), (int) ($result->stats['api_pages'] ?? 0));
        echo '</div></div></div>';

        if ($result->mappingQuality !== []) {
            echo '<div class="card mb-3 border-0 shadow-sm">';
            echo '<div class="card-header bg-light border-0 fw-semibold py-2">';
            echo '<i class="ti ti-target-arrow me-1"></i>' . self::h(__('Mapping quality', 'bridge'));
            echo '</div><div class="card-body py-2">';
            echo '<div class="d-flex flex-wrap gap-2 small">';
            self::metricPill(__('Clean matches', 'bridge'), (int) ($result->mappingQuality['ok'] ?? 0));
            self::metricPill(__('Fallbacks used', 'bridge'), (int) ($result->mappingQuality['partial'] ?? 0));
            self::metricPill(__('Unresolved', 'bridge'), (int) ($result->mappingQuality['unresolved'] ?? 0));
            self::metricPill(__('Duplicates', 'bridge'), (int) ($result->mappingQuality['duplicate'] ?? 0));
            self::metricPill(__('API failures', 'bridge'), (int) ($result->mappingQuality['failed'] ?? 0));
            echo '</div>';
            self::showWarningSummary($result);
            echo '</div></div>';
        }

        if ($candidates === 0) {
            echo '<div class="alert alert-warning d-flex align-items-center gap-2">';
            echo '<i class="ti ti-alert-triangle"></i>';
            echo '<span>' . self::h(__('No migratable candidates were found for these filters. Adjust the filters or purge duplicates from history before creating a job.', 'bridge')) . '</span>';
            echo '</div>';
        } elseif ($duplicates > 0 && $duplicates >= $candidates) {
            echo '<div class="alert alert-warning d-flex align-items-center gap-2">';
            echo '<i class="ti ti-alert-triangle"></i>';
            echo '<span>' . self::h(__('Most sampled records were already migrated. The job will skip duplicates and only process new candidates.', 'bridge')) . '</span>';
            echo '</div>';
        }

        self::showPreflightTable($result, $resourceType);

        echo '<div class="d-flex align-items-center justify-content-between mt-3">';
        echo '<div class="text-muted small">';
        echo '<i class="ti ti-info-circle me-1"></i>' . self::h(__('A job will be created only after confirmation.', 'bridge'));
        echo '</div>';
        echo '<div class="d-flex gap-2">';
        echo '<a class="btn btn-outline-secondary" href="' . self::h($migrateUrl . '?id=' . $id . '&resource_type=' . rawurlencode($resourceType)) . '">';
        echo '<i class="ti ti-adjustments me-1"></i>' . self::h(__('Edit filters', 'bridge'));
        echo '</a>';
        if ($candidates > 0) {
            echo '<form method="post" action="' . self::h($migrateUrl) . '" class="d-inline">';
            echo \Html::hidden('_glpi_csrf_token', ['value' => \Session::getNewCSRFToken()]);
            echo \Html::hidden('id', ['value' => $id]);
            echo \Html::hidden('action', ['value' => 'migrate']);
            echo \Html::hidden('confirm_preflight', ['value' => '1']);
            echo \Html::hidden('resource_type', ['value' => $resourceType]);
            foreach ($options as $key => $value) {
                if (is_bool($value)) {
                    if ($value) {
                        echo \Html::hidden($key, ['value' => '1']);
                    }
                } elseif (is_scalar($value)) {
                    echo \Html::hidden($key, ['value' => (string) $value]);
                }
            }
            echo '<button type="submit" class="btn btn-primary">';
            echo '<i class="ti ti-player-play me-1"></i>' . self::h(__('Create migration job', 'bridge'));
            echo '</button></form>';
        }
        echo '</div></div>';

        echo '</div>';
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private static function sectionCard(string $icon, string $title): string
    {
        return '<div class="bridge-section-card">'
            . '<div class="bridge-section-title"><i class="ti ' . $icon . '"></i>' . self::h($title) . '</div>';
    }

    private static function checkbox(string $id, string $name, string $label, string $icon, bool $checked): string
    {
        $ch = $checked ? ' checked' : '';
        return '<div class="form-check">'
            . '<input class="form-check-input" type="checkbox" id="' . $id . '" name="' . $name . '" value="1"' . $ch . '>'
            . '<label class="form-check-label small" for="' . $id . '">'
            . '<i class="ti ' . $icon . ' me-1 text-muted"></i>' . $label
            . '</label>'
            . '</div>';
    }

    private static function statCard(string $icon, string $color, int $value, string $label): void
    {
        echo '<div class="col-md-4">';
        echo '<div class="card border-0 shadow-sm h-100">';
        echo '<div class="card-body py-3 d-flex align-items-center gap-3">';
        echo '<div class="rounded-circle d-flex align-items-center justify-content-center text-' . $color . '" style="width:3rem;height:3rem;background:var(--bs-' . $color . '-bg-subtle,rgba(0,0,0,.05))">';
        echo '<i class="ti ti-' . $icon . '" style="font-size:1.4rem"></i>';
        echo '</div>';
        echo '<div>';
        echo '<div class="fw-bold fs-3 lh-1">' . $value . '</div>';
        echo '<div class="text-muted small mt-1">' . self::h($label) . '</div>';
        echo '</div>';
        echo '</div></div></div>';
    }

    private static function metricPill(string $label, int $value): void
    {
        echo '<span class="badge bg-secondary-subtle text-secondary border">';
        echo self::h($label) . ': <strong>' . $value . '</strong>';
        echo '</span>';
    }

    private static function showPreflightTable(MigrationResult $result, string $resourceType): void
    {
        $rows = $result->preflightRows;

        if ($rows === []) {
            return;
        }

        echo '<div class="card mb-3 border-0 shadow-sm">';
        echo '<div class="card-header bg-light border-0 fw-semibold py-2">';
        echo '<i class="ti ti-list-check me-1"></i>' . self::h(__('Sample review', 'bridge'));
        echo '</div>';
        echo '<div class="table-responsive"><table class="table table-sm table-hover mb-0">';
        echo '<thead class="table-light"><tr>';
        echo '<th class="text-muted fw-normal small">#SW</th>';
        echo '<th class="fw-normal">' . self::h(__('Name', 'bridge')) . '</th>';
        echo '<th class="fw-normal">' . self::h(__('Status', 'bridge')) . '</th>';
        echo '<th class="fw-normal">' . self::h(__('Notes', 'bridge')) . '</th>';
        echo '</tr></thead><tbody>';

        foreach (array_slice($rows, 0, 50) as $row) {
            $status = (string) ($row['status'] ?? '');
            $badge = match ($status) {
                'ok' => '<span class="badge bg-success">' . self::h(__('Clean', 'bridge')) . '</span>',
                'partial' => '<span class="badge bg-warning text-dark">' . self::h(__('Fallback', 'bridge')) . '</span>',
                'duplicate' => '<span class="badge bg-secondary">' . self::h(__('Duplicate', 'bridge')) . '</span>',
                'failed' => '<span class="badge bg-danger">' . self::h(__('API failed', 'bridge')) . '</span>',
                default => '<span class="badge bg-danger">' . self::h(__('Blocked', 'bridge')) . '</span>',
            };
            $note = match ($status) {
                'ok' => __('Ready for job creation', 'bridge'),
                'partial' => implode(' | ', array_slice($row['warnings'] ?? [], 0, 3)),
                'duplicate' => __('Already migrated successfully', 'bridge'),
                default => (string) ($row['reason'] ?? ''),
            };

            echo '<tr>';
            echo '<td class="text-muted small">' . self::h($row['number'] ?? '') . '</td>';
            echo '<td>' . self::h($row['name'] ?? '') . '</td>';
            echo '<td>' . $badge . '</td>';
            echo '<td class="text-muted small">' . self::h($note) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table></div>';
        echo '</div>';
    }

    private static function showWarningSummary(MigrationResult $result): void
    {
        $warnings = [];
        foreach ($result->preflightRows as $row) {
            foreach (($row['warnings'] ?? []) as $warning) {
                $warning = (string) $warning;
                if ($warning !== '') {
                    $warnings[$warning] = ($warnings[$warning] ?? 0) + 1;
                }
            }
        }

        if ($warnings === []) {
            return;
        }

        arsort($warnings);
        echo '<details class="mt-2">';
        echo '<summary class="text-muted small" style="cursor:pointer">';
        echo '<i class="ti ti-alert-triangle me-1"></i>' . self::h(__('Mapping warnings', 'bridge'));
        echo '</summary>';
        echo '<ul class="small mt-2 mb-0">';
        foreach (array_slice($warnings, 0, 12, true) as $warning => $count) {
            echo '<li><span class="badge bg-warning text-dark me-1">' . (int) $count . '</span>' . self::h($warning) . '</li>';
        }
        echo '</ul>';
        echo '</details>';
    }

    private static function h(mixed $v): string
    {
        return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    }
}

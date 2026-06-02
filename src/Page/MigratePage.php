<?php

namespace GlpiPlugin\Bridge\Page;

use GlpiPlugin\Bridge\Connection;
use GlpiPlugin\Bridge\Migration\MigrationResult;

class MigratePage
{
    public static function showForm(
        Connection $connection,
        array      $resourceTypes,
        string     $migrateUrl,
        string     $historyUrl
    ): void {
        $id  = (int) $connection->fields['id'];
        $key = 'bridge_form_' . $id; // sessionStorage key per connection

        echo '<div class="container-fluid py-3 px-4" style="max-width:780px">';

        // ── Header ───────────────────────────────────────────────────────
        echo '<div class="d-flex align-items-center justify-content-between mb-4">';
        echo '<div>';
        echo '<h4 class="mb-0"><i class="ti ti-database-import me-2 text-primary"></i>' . self::h(__('Migration', 'bridge')) . '</h4>';
        echo '<div class="text-muted small mt-1"><i class="ti ti-plug me-1"></i><strong>' . self::h($connection->fields['name']) . '</strong> &mdash; ' . self::h($connection->fields['base_url'] ?? '') . '</div>';
        echo '</div>';
        echo '<div class="d-flex gap-2">';
        echo '<a class="btn btn-sm btn-outline-secondary" href="' . self::h($historyUrl . '?id=' . $id) . '"><i class="ti ti-history me-1"></i>' . self::h(__('History', 'bridge')) . '</a>';
        echo '<a class="btn btn-sm btn-outline-secondary" href="' . self::h(Connection::getConfigURL($id)) . '"><i class="ti ti-arrow-left me-1"></i>' . self::h(__('Back', 'bridge')) . '</a>';
        echo '</div>';
        echo '</div>';

        echo '<form method="post" action="' . self::h($migrateUrl) . '" id="bridge-migrate-form">';
        echo \Html::hidden('_glpi_csrf_token', ['value' => \Session::getNewCSRFToken()]);
        echo \Html::hidden('id', ['value' => $id]);

        // ── Resource type ────────────────────────────────────────────────
        echo self::sectionCard('ti-box', __('Resource type', 'bridge'));
        echo '<div class="d-flex flex-wrap gap-2">';
        $first = true;
        foreach ($resourceTypes as $key2 => $meta) {
            $implemented = (bool) ($meta['implemented'] ?? false);
            $label       = self::h((string) ($meta['label'] ?? $key2));
            if ($implemented) {
                $checked = $first ? ' checked' : '';
                echo '<label class="bridge-pill' . ($first ? ' active' : '') . '">';
                echo '<input type="radio" name="resource_type" value="' . self::h($key2) . '"' . $checked . ' onchange="this.closest(\'.d-flex\').querySelectorAll(\'.bridge-pill\').forEach(p=>p.classList.remove(\'active\')); this.parentElement.classList.add(\'active\')">';
                echo $label;
                echo '</label>';
                $first = false;
            } else {
                echo '<span class="bridge-pill disabled"><i class="ti ti-lock me-1"></i>' . $label . ' <span class="badge bg-secondary ms-1 fw-normal" style="font-size:.7rem">' . self::h(__('Soon', 'bridge')) . '</span></span>';
            }
        }
        echo '</div>';
        echo '</div>'; // card

        // ── Migration mode ───────────────────────────────────────────────
        echo self::sectionCard('ti-adjustments-horizontal', __('Mode', 'bridge'));
        echo '<div class="d-flex gap-2 mb-3" role="group">';
        echo '<button type="button" id="btn-mode-filters" class="btn btn-sm bridge-mode-btn active" onclick="bridgeSetMode(\'filters\')">';
        echo '<i class="ti ti-filter me-1"></i>' . self::h(__('By filters', 'bridge'));
        echo '</button>';
        echo '<button type="button" id="btn-mode-ids" class="btn btn-sm bridge-mode-btn" onclick="bridgeSetMode(\'ids\')">';
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
            echo '<input type="radio" name="state" id="f_state_' . self::h($val ?: 'all') . '" value="' . self::h($val) . '"' . $checked . ' onchange="bridgeStatePill(this)">';
            echo self::h($lbl);
            echo '</label>';
            $first = false;
        }
        echo '</div>';
        echo '</div>';

        // Date range
        echo '<div class="row g-3 mb-3">';
        echo '<div class="col-md-6">';
        echo '<label class="form-label fw-medium">' . self::h(__('Created after', 'bridge')) . '</label>';
        echo '<input type="date" class="form-control form-control-sm" name="created_after" id="f_created_after">';
        echo '<div class="form-text">' . self::h(__('Leave empty to fetch from the most recent.', 'bridge')) . '</div>';
        echo '</div>';
        echo '<div class="col-md-6">';
        echo '<label class="form-label fw-medium">' . self::h(__('Updated after', 'bridge')) . '</label>';
        echo '<input type="date" class="form-control form-control-sm" name="updated_after" id="f_updated_after">';
        echo '<div class="form-text">' . self::h(__('For incremental sync — only tickets modified since this date.', 'bridge')) . '</div>';
        echo '</div>';
        echo '</div>';

        // Limit
        echo '<div class="row g-3 mb-2">';
        echo '<div class="col-md-4">';
        echo '<label class="form-label fw-medium">' . self::h(__('Max per run', 'bridge')) . '</label>';
        echo '<input type="number" class="form-control form-control-sm" name="limit" id="f_limit" value="50" min="1" max="500">';
        echo '</div>';
        echo '</div>';

        // Advanced: pagination
        echo '<details class="mt-1">';
        echo '<summary class="text-muted small" style="cursor:pointer;user-select:none">';
        echo '<i class="ti ti-settings me-1"></i>' . self::h(__('Advanced — pagination', 'bridge'));
        echo '</summary>';
        echo '<div class="mt-2 p-3 border rounded bg-light">';
        echo '<div class="row g-3">';
        echo '<div class="col-md-5">';
        echo '<label class="form-label fw-medium small">' . self::h(__('Start from page', 'bridge')) . '</label>';
        echo '<input type="number" class="form-control form-control-sm" name="start_page" id="f_start_page" value="1" min="1">';
        echo '</div>';
        echo '<div class="col-md-7 d-flex align-items-end">';
        echo '<div class="form-text mb-0">';
        echo '<i class="ti ti-info-circle me-1"></i>';
        echo self::h(__('The API returns newest first. Page 1 = today. Use this to reach historical tickets.', 'bridge'));
        echo '</div>';
        echo '</div>';
        echo '</div>';
        echo '</div>';
        echo '</details>';

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
        ]);
        echo '<div class="form-text">' . self::h(__('Used when source has no requester email.', 'bridge')) . '</div>';
        echo '</div>';

        echo '<div class="col-md-6 d-flex flex-column justify-content-center gap-2 pt-2">';
        echo self::checkbox('inc_comments',   'include_comments',       __('Comments → Followups', 'bridge'),           'ti-message',   true);
        echo self::checkbox('inc_attachments','include_attachments',    __('Attachments → Documents', 'bridge') . ' <span class="text-muted small">(' . self::h(__('slower', 'bridge')) . ')</span>', 'ti-paperclip', false);
        echo self::checkbox('keep_private',   'keep_private_comments',  __('Preserve private flag on comments', 'bridge'), 'ti-lock',    true);
        echo '</div>';

        echo '</div>'; // row
        echo '</div>'; // card

        // ── Action buttons ───────────────────────────────────────────────
        echo '<div class="d-flex gap-2 mt-2">';
        echo '<button type="submit" name="action" value="migrate" class="btn btn-primary">';
        echo '<i class="ti ti-database-import me-1"></i>' . self::h(__('Migrate now', 'bridge'));
        echo '</button>';
        echo '<button type="submit" name="action" value="dryrun" class="btn btn-outline-warning">';
        echo '<i class="ti ti-list-check me-1"></i>' . self::h(__('Dry-run preview', 'bridge'));
        echo '</button>';
        echo '</div>';

        echo '</form>';

        // ── Styles + JS ──────────────────────────────────────────────────
        $stKey = self::h('bridge_state_' . $id);

        echo <<<HTML
<style>
.bridge-pill {
    display:inline-flex; align-items:center; gap:.35rem;
    padding:.35rem .85rem; border-radius:2rem;
    border:1px solid #dee2e6; background:#fff;
    cursor:pointer; font-size:.875rem; transition:all .15s;
    user-select:none;
}
.bridge-pill input[type=radio] { display:none; }
.bridge-pill.active { border-color:#0d6efd; background:#e8f0fe; color:#0d6efd; font-weight:500; }
.bridge-pill.disabled { opacity:.5; cursor:default; }

.bridge-mode-btn {
    border:1px solid #dee2e6; background:#fff; color:#495057;
    padding:.3rem .9rem; border-radius:.375rem;
}
.bridge-mode-btn.active {
    background:#0d6efd; border-color:#0d6efd; color:#fff;
}

.bridge-state-pill {
    display:inline-flex; align-items:center; padding:.25rem .75rem;
    border-radius:2rem; border:1px solid #dee2e6; background:#fff;
    cursor:pointer; font-size:.8rem; transition:all .15s; user-select:none;
}
.bridge-state-pill input[type=radio] { display:none; }
.bridge-state-pill.active { color:#fff; border-color:currentColor; }
.bridge-state-pill.active[data-color=secondary] { background:#6c757d; border-color:#6c757d; }
.bridge-state-pill.active[data-color=success]   { background:#198754; border-color:#198754; }
.bridge-state-pill.active[data-color=primary]   { background:#0d6efd; border-color:#0d6efd; }
.bridge-state-pill.active[data-color=warning]   { background:#ffc107; border-color:#ffc107; color:#000; }
.bridge-state-pill.active[data-color=info]      { background:#0dcaf0; border-color:#0dcaf0; color:#000; }

.bridge-section-card {
    border:1px solid #e9ecef; border-radius:.5rem;
    padding:1rem 1.25rem; margin-bottom:1rem; background:#fff;
}
.bridge-section-card .bridge-section-title {
    font-size:.7rem; font-weight:600; text-transform:uppercase;
    letter-spacing:.06em; color:#6c757d; margin-bottom:.75rem;
    display:flex; align-items:center; gap:.4rem;
}
</style>
<script>
(function() {
    var SK = '{$stKey}';

    function bridgeSetMode(mode) {
        var isIds = (mode === 'ids');
        document.getElementById('bridge_section_ids').style.display     = isIds ? '' : 'none';
        document.getElementById('bridge_section_filters').style.display = isIds ? 'none' : '';
        document.getElementById('migration_mode_val').value = mode;
        document.getElementById('btn-mode-filters').classList.toggle('active', !isIds);
        document.getElementById('btn-mode-ids').classList.toggle('active',  isIds);
        try { sessionStorage.setItem(SK + '_mode', mode); } catch(e) {}
    }
    window.bridgeSetMode = bridgeSetMode;

    window.bridgeStatePill = function(radio) {
        document.querySelectorAll('.bridge-state-pill').forEach(function(p){ p.classList.remove('active'); });
        if (radio.checked) radio.closest('.bridge-state-pill').classList.add('active');
    };
    // Init active pill on load
    document.addEventListener('DOMContentLoaded', function(){
        document.querySelectorAll('.bridge-state-pill input:checked').forEach(function(r){
            r.closest('.bridge-state-pill').classList.add('active');
        });
    });

    function restore() {
        try {
            var mode = sessionStorage.getItem(SK + '_mode') || 'filters';
            bridgeSetMode(mode);

            var ids = sessionStorage.getItem(SK + '_ids') || '';
            if (ids) document.getElementById('f_source_ids').value = ids;

            var state = sessionStorage.getItem(SK + '_state') || '';
            if (state) document.getElementById('f_state').value = state;

            var ca = sessionStorage.getItem(SK + '_created_after') || '';
            if (ca) document.getElementById('f_created_after').value = ca;

            var ua = sessionStorage.getItem(SK + '_updated_after') || '';
            if (ua) document.getElementById('f_updated_after').value = ua;

            var sp = sessionStorage.getItem(SK + '_start_page');
            if (sp) document.getElementById('f_start_page').value = sp;

            var lim = sessionStorage.getItem(SK + '_limit');
            if (lim) document.getElementById('f_limit').value = lim;
        } catch(e) {}
    }

    document.addEventListener('DOMContentLoaded', function() {
        restore();

        document.getElementById('bridge-migrate-form').addEventListener('submit', function() {
            try {
                sessionStorage.setItem(SK + '_mode',  document.getElementById('migration_mode_val').value);
                sessionStorage.setItem(SK + '_ids',   document.getElementById('f_source_ids').value);
                sessionStorage.setItem(SK + '_state', document.getElementById('f_state').value);
                sessionStorage.setItem(SK + '_created_after', document.getElementById('f_created_after').value);
                sessionStorage.setItem(SK + '_updated_after', document.getElementById('f_updated_after').value);
                sessionStorage.setItem(SK + '_start_page', document.getElementById('f_start_page').value);
                sessionStorage.setItem(SK + '_limit', document.getElementById('f_limit').value);
            } catch(e) {}
        });
    });
})();
</script>
HTML;

        echo '</div>';
    }

    public static function showResult(
        Connection      $connection,
        MigrationResult $result,
        string          $resourceType,
        string          $historyUrl
    ): void {
        $id    = (int) $connection->fields['id'];
        $isDry = $result->isDryRun;
        $title = $isDry ? __('Dry-run preview', 'bridge') : __('Migration complete', 'bridge');
        $icon  = $isDry ? 'list-check text-warning' : 'circle-check text-success';

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
                    $ticketUrl = \Ticket::getFormURLWithID((int) $r['tickets_id']);
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

    private static function h(mixed $v): string
    {
        return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    }
}

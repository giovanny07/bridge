<?php

namespace GlpiPlugin\Bridge\Page;

use GlpiPlugin\Bridge\Connection;
use GlpiPlugin\Bridge\Migration\UserSyncResult;

class SyncUsersPage
{
    public static function showForm(Connection $connection, string $syncUrl): void
    {
        $id  = (int) $connection->fields['id'];
        $key = self::h('bridge_usersync_' . $id);

        echo '<div class="container-fluid py-3 px-4" style="max-width:780px">';

        // ── Header ───────────────────────────────────────────────────────
        echo '<div class="d-flex align-items-center justify-content-between mb-4">';
        echo '<div>';
        echo '<h4 class="mb-0"><i class="ti ti-users me-2 text-primary"></i>' . self::h(__('User sync', 'bridge')) . '</h4>';
        echo '<div class="text-muted small mt-1"><i class="ti ti-plug me-1"></i><strong>' . self::h($connection->fields['name']) . '</strong> &mdash; ' . self::h($connection->fields['base_url'] ?? '') . '</div>';
        echo '</div>';
        echo '<a class="btn btn-sm btn-outline-secondary" href="' . self::h(Connection::getConfigURL($id)) . '"><i class="ti ti-arrow-left me-1"></i>' . self::h(__('Back', 'bridge')) . '</a>';
        echo '</div>';

        echo '<form method="post" action="' . self::h($syncUrl) . '" id="bridge-usersync-form">';
        echo \Html::hidden('_glpi_csrf_token', ['value' => \Session::getNewCSRFToken()]);
        echo \Html::hidden('id', ['value' => $id]);

        // ── Mode ─────────────────────────────────────────────────────────
        echo self::card('ti-adjustments-horizontal', __('Mode', 'bridge'));
        echo '<div class="d-flex gap-2 mb-3">';
        echo '<button type="button" id="btn-mode-all" class="btn btn-sm bridge-mode-btn active" onclick="bridgeUserMode(\'all\')">';
        echo '<i class="ti ti-users me-1"></i>' . self::h(__('All users', 'bridge'));
        echo '</button>';
        echo '<button type="button" id="btn-mode-ids" class="btn btn-sm bridge-mode-btn" onclick="bridgeUserMode(\'ids\')">';
        echo '<i class="ti ti-hash me-1"></i>' . self::h(__('By SW user IDs', 'bridge'));
        echo '</button>';
        echo '</div>';
        echo '<input type="hidden" name="sync_mode" id="sync_mode_val" value="all">';

        // Mode: All users — filters + pagination
        echo '<div id="bridge_user_section_all">';
        echo '<div class="row g-3">';

        echo '<div class="col-md-4">';
        echo '<label class="form-label fw-medium">' . self::h(__('Role filter', 'bridge')) . '</label>';
        echo '<input type="text" class="form-control form-control-sm" name="role_filter" id="f_role" placeholder="' . self::h(__('e.g. Especialista (empty = all)', 'bridge')) . '">';
        echo '</div>';

        echo '<div class="col-md-4">';
        echo '<label class="form-label fw-medium">' . self::h(__('Start from page', 'bridge')) . '</label>';
        echo '<input type="number" class="form-control form-control-sm" name="start_page" id="f_start_page" value="1" min="1">';
        echo '</div>';

        echo '<div class="col-md-4">';
        echo '<label class="form-label fw-medium">' . self::h(__('Limit', 'bridge')) . '</label>';
        echo '<input type="number" class="form-control form-control-sm" name="limit" id="f_limit" value="200" min="1" max="2000">';
        echo '<div class="form-text">' . self::h(__('Max users per run.', 'bridge')) . '</div>';
        echo '</div>';

        echo '</div>';
        echo '</div>'; // section all

        // Mode: By IDs
        echo '<div id="bridge_user_section_ids" style="display:none">';
        echo '<label class="form-label fw-medium">' . self::h(__('SW User IDs (comma-separated)', 'bridge')) . '</label>';
        echo '<input type="text" class="form-control form-control-sm font-monospace" name="source_ids" id="f_source_ids" placeholder="9515238, 9503669, ..." autocomplete="off">';
        echo '<div class="form-text">' . self::h(__('SolarWinds user IDs. Overrides filters and limit.', 'bridge')) . '</div>';
        echo '</div>';

        echo '</div>'; // card

        // ── Options ───────────────────────────────────────────────────────
        echo self::card('ti-settings-2', __('Options', 'bridge'));
        echo '<div class="d-flex flex-wrap gap-3">';
        echo self::checkbox('skip_disabled',   'skip_disabled',   __('Skip disabled/inactive users', 'bridge'),   'ti-user-off', true);
        echo self::checkbox('update_existing', 'update_existing', __('Update existing users (name, phone)', 'bridge'), 'ti-refresh', false);
        echo '</div>';
        echo '<div class="alert alert-light border mt-3 mb-0 py-2 small">';
        echo '<i class="ti ti-info-circle me-1 text-primary"></i>';
        echo self::h(__('Only user records and email addresses are created/updated. Profile assignments must be done manually in GLPI.', 'bridge'));
        echo '</div>';
        echo '</div>'; // card

        // ── Actions ───────────────────────────────────────────────────────
        echo '<div class="d-flex gap-2 mt-2">';
        echo '<button type="submit" name="action" value="sync" class="btn btn-primary">';
        echo '<i class="ti ti-users me-1"></i>' . self::h(__('Sync users', 'bridge'));
        echo '</button>';
        echo '<button type="submit" name="action" value="dryrun" class="btn btn-outline-warning">';
        echo '<i class="ti ti-list-check me-1"></i>' . self::h(__('Dry-run preview', 'bridge'));
        echo '</button>';
        echo '</div>';

        echo '</form>';

        echo <<<JS
<style>
.bridge-mode-btn {
    border:1px solid #dee2e6; background:#fff; color:#495057; padding:.3rem .9rem; border-radius:.375rem;
}
.bridge-mode-btn.active { background:#0d6efd; border-color:#0d6efd; color:#fff; }
</style>
<script>
(function(){
    var SK = '{$key}';
    function bridgeUserMode(mode) {
        var isIds = mode === 'ids';
        document.getElementById('bridge_user_section_ids').style.display = isIds ? '' : 'none';
        document.getElementById('bridge_user_section_all').style.display = isIds ? 'none' : '';
        document.getElementById('sync_mode_val').value = mode;
        document.getElementById('btn-mode-all').classList.toggle('active', !isIds);
        document.getElementById('btn-mode-ids').classList.toggle('active',  isIds);
        try { sessionStorage.setItem(SK + '_mode', mode); } catch(e){}
    }
    window.bridgeUserMode = bridgeUserMode;
    document.addEventListener('DOMContentLoaded', function(){
        try { var m = sessionStorage.getItem(SK + '_mode'); if(m) bridgeUserMode(m); } catch(e){}
        document.getElementById('bridge-usersync-form').addEventListener('submit', function(){
            try {
                sessionStorage.setItem(SK + '_mode', document.getElementById('sync_mode_val').value);
                sessionStorage.setItem(SK + '_ids',  document.getElementById('f_source_ids').value);
            } catch(e){}
        });
    });
})();
</script>
JS;

        echo '</div>';
    }

    public static function showResult(Connection $connection, UserSyncResult $result, string $syncUrl): void
    {
        $id    = (int) $connection->fields['id'];
        $isDry = $result->isDryRun;
        $title = $isDry ? __('Dry-run preview', 'bridge') : __('Sync complete', 'bridge');
        $icon  = $isDry ? 'list-check text-warning' : 'circle-check text-success';

        echo '<div class="container-fluid py-3 px-4" style="max-width:900px">';

        echo '<div class="d-flex align-items-center justify-content-between mb-4">';
        echo '<h4 class="mb-0"><i class="ti ti-' . $icon . ' me-2"></i>' . self::h($title) . '</h4>';
        echo '<div class="d-flex gap-2">';
        echo '<a class="btn btn-sm btn-outline-primary" href="' . self::h($syncUrl . '?id=' . $id) . '"><i class="ti ti-users me-1"></i>' . self::h(__('Sync again', 'bridge')) . '</a>';
        echo '<a class="btn btn-sm btn-outline-secondary" href="' . self::h(Connection::getConfigURL($id)) . '"><i class="ti ti-arrow-left me-1"></i>' . self::h(__('Back', 'bridge')) . '</a>';
        echo '</div>';
        echo '</div>';

        if ($isDry) {
            echo '<div class="alert alert-warning d-flex align-items-center gap-2 mb-4">';
            echo '<i class="ti ti-alert-triangle fs-5"></i>';
            echo '<span>' . self::h(__('Dry-run — no changes were written to GLPI.', 'bridge')) . '</span>';
            echo '</div>';
        }

        // Stat cards
        echo '<div class="row g-3 mb-4">';
        self::statCard('user-plus',  'success',   count($result->created), $isDry ? __('Would create', 'bridge') : __('Created', 'bridge'));
        self::statCard('refresh',    'info',       count($result->updated), __('Updated', 'bridge'));
        self::statCard('user-check', 'secondary',  count($result->skipped), __('Skipped (exists)', 'bridge'));
        self::statCard('user-x',     'danger',     count($result->failed),  __('Failed', 'bridge'));
        echo '</div>';

        // Created
        if (!empty($result->created)) {
            self::resultTable(
                $isDry ? __('Would create', 'bridge') : __('Created', 'bridge'),
                'success', $result->created, false
            );
        }

        // Updated
        if (!empty($result->updated)) {
            self::resultTable(__('Updated', 'bridge'), 'info', $result->updated, false);
        }

        // Failed
        if (!empty($result->failed)) {
            self::resultTable(__('Failed', 'bridge'), 'danger', $result->failed, true);
        }

        echo '</div>';
    }

    // ------------------------------------------------------------------ //

    private static function resultTable(string $title, string $color, array $rows, bool $showError): void
    {
        echo '<div class="card border-0 shadow-sm mb-3">';
        echo '<div class="card-header bg-' . $color . ' bg-opacity-10 border-0 fw-semibold text-' . $color . ' py-2">';
        echo self::h($title) . ' <span class="badge bg-' . $color . ' ms-1">' . count($rows) . '</span>';
        echo '</div>';
        echo '<div class="table-responsive"><table class="table table-sm table-hover mb-0" style="font-size:.82rem">';
        echo '<thead class="table-light"><tr>';
        echo '<th class="text-muted fw-normal">SW ID</th>';
        echo '<th class="fw-normal">' . self::h(__('Name', 'bridge')) . '</th>';
        echo '<th class="fw-normal">Email</th>';
        echo '<th class="fw-normal">' . self::h(__('Site / Entity', 'bridge')) . '</th>';
        if ($showError) echo '<th class="fw-normal text-danger">' . self::h(__('Error', 'bridge')) . '</th>';
        echo '</tr></thead><tbody>';
        foreach ($rows as $r) {
            echo '<tr>';
            echo '<td class="text-muted">' . self::h((string) ($r['sw_id'] ?? '')) . '</td>';
            echo '<td>' . self::h((string) ($r['name']   ?? '')) . '</td>';
            echo '<td class="font-monospace small">' . self::h((string) ($r['email']  ?? '')) . '</td>';
            echo '<td class="small text-muted">' . self::h((string) ($r['entity'] ?? '')) . '</td>';
            if ($showError) echo '<td class="text-danger small">' . self::h((string) ($r['reason'] ?? '')) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table></div></div>';
    }

    private static function card(string $icon, string $title): string
    {
        return '<div class="bridge-section-card" style="border:1px solid #e9ecef;border-radius:.5rem;padding:1rem 1.25rem;margin-bottom:1rem;background:#fff">'
            . '<div style="font-size:.7rem;font-weight:600;text-transform:uppercase;letter-spacing:.06em;color:#6c757d;margin-bottom:.75rem;display:flex;align-items:center;gap:.4rem">'
            . '<i class="ti ' . $icon . '"></i>' . self::h($title) . '</div>';
    }

    private static function checkbox(string $id, string $name, string $label, string $icon, bool $checked): string
    {
        $ch = $checked ? ' checked' : '';
        return '<div class="form-check">'
            . '<input class="form-check-input" type="checkbox" id="' . $id . '" name="' . $name . '" value="1"' . $ch . '>'
            . '<label class="form-check-label small" for="' . $id . '"><i class="ti ' . $icon . ' me-1 text-muted"></i>' . $label . '</label>'
            . '</div>';
    }

    private static function statCard(string $icon, string $color, int $value, string $label): void
    {
        echo '<div class="col-6 col-md-3">';
        echo '<div class="card border-0 shadow-sm h-100"><div class="card-body py-3 d-flex align-items-center gap-3">';
        echo '<div class="rounded-circle d-flex align-items-center justify-content-center text-' . $color . '" style="width:2.8rem;height:2.8rem;background:var(--bs-' . $color . '-bg-subtle,rgba(0,0,0,.06))">';
        echo '<i class="ti ti-' . $icon . '" style="font-size:1.2rem"></i></div>';
        echo '<div><div class="fw-bold fs-4 lh-1">' . $value . '</div><div class="text-muted small mt-1">' . self::h($label) . '</div></div>';
        echo '</div></div></div>';
    }

    private static function h(mixed $v): string
    {
        return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    }
}

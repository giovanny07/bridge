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
        $id = (int) $connection->fields['id'];

        echo '<div class="container-fluid p-3" style="max-width:700px">';

        // Header
        echo '<div class="d-flex align-items-center justify-content-between mb-3">';
        echo '<h4 class="m-0"><i class="ti ti-database-import me-2 text-primary"></i>';
        echo self::h(__('Migration', 'bridge')) . '</h4>';
        echo '<div class="d-flex gap-2">';
        echo '<a class="btn btn-outline-secondary btn-sm" href="' . self::h($historyUrl . '?id=' . $id) . '">';
        echo '<i class="ti ti-history me-1"></i>' . self::h(__('History', 'bridge'));
        echo '</a>';
        echo '<a class="btn btn-outline-secondary btn-sm" href="' . self::h(Connection::getConfigURL($id)) . '">';
        echo '<i class="ti ti-arrow-left me-1"></i>' . self::h(__('Back', 'bridge'));
        echo '</a>';
        echo '</div>';
        echo '</div>';

        echo '<form method="post" action="' . self::h($migrateUrl) . '">';
        echo \Html::hidden('_glpi_csrf_token', ['value' => \Session::getNewCSRFToken()]);
        echo \Html::hidden('id', ['value' => $id]);

        // Connection info
        echo '<div class="alert alert-light border mb-3 py-2">';
        echo '<i class="ti ti-plug me-1"></i><strong>' . self::h($connection->fields['name']) . '</strong>';
        echo ' &mdash; ' . self::h($connection->fields['base_url'] ?? '');
        echo '</div>';

        echo '<div class="row g-3">';

        // Resource type
        echo '<div class="col-12">';
        echo '<label class="form-label fw-semibold">' . self::h(__('Resource type', 'bridge')) . '</label>';
        echo '<div class="d-flex flex-wrap gap-2">';
        $first = true;
        foreach ($resourceTypes as $key => $meta) {
            $implemented = (bool) ($meta['implemented'] ?? false);
            $label       = self::h((string) ($meta['label'] ?? $key));
            if ($implemented) {
                $checked = $first ? ' checked' : '';
                echo '<div class="form-check form-check-inline border rounded px-3 py-2">';
                echo '<input class="form-check-input" type="radio" name="resource_type" id="rt_' . $key . '" value="' . self::h($key) . '"' . $checked . '>';
                echo '<label class="form-check-label" for="rt_' . $key . '">' . $label . '</label>';
                echo '</div>';
                $first = false;
            } else {
                echo '<div class="border rounded px-3 py-2 text-muted bg-light d-flex align-items-center gap-2">';
                echo '<i class="ti ti-lock text-muted"></i>' . $label;
                echo '<span class="badge bg-secondary ms-1">' . self::h(__('Not implemented yet', 'bridge')) . '</span>';
                echo '</div>';
            }
        }
        echo '</div>';
        echo '</div>';

        // Filters
        echo '<div class="col-12"><hr class="my-1"></div>';
        echo '<div class="col-12"><p class="fw-semibold mb-1">' . self::h(__('Filters', 'bridge')) . '</p></div>';

        echo '<div class="col-md-4">';
        echo '<label class="form-label">' . self::h(__('State', 'bridge')) . '</label>';
        $states = [
            ''                         => __('All states', 'bridge'),
            'Closed'                   => 'Closed',
            'Solucionado'              => 'Solucionado',
            'En Proceso'               => 'En Proceso',
            'Pending Assignment'       => 'Pending Assignment',
            'Pendiente Acción Cliente' => 'Pendiente Acción Cliente',
            'Gestión Proveedor'        => 'Gestión Proveedor',
        ];
        echo '<select class="form-select" name="state">';
        foreach ($states as $val => $lbl) {
            echo '<option value="' . self::h($val) . '">' . self::h($lbl) . '</option>';
        }
        echo '</select>';
        echo '</div>';

        echo '<div class="col-md-4">';
        echo '<label class="form-label">' . self::h(__('Created after', 'bridge')) . '</label>';
        echo '<input type="date" class="form-control" name="created_after">';
        echo '</div>';

        echo '<div class="col-md-4">';
        echo '<label class="form-label">' . self::h(__('Updated after', 'bridge')) . '</label>';
        echo '<input type="date" class="form-control" name="updated_after">';
        echo '</div>';

        // Batch
        echo '<div class="col-12"><hr class="my-1"></div>';
        echo '<div class="col-12"><p class="fw-semibold mb-1">' . self::h(__('Batch', 'bridge')) . '</p></div>';

        echo '<div class="col-md-4">';
        echo '<label class="form-label">' . self::h(__('Limit', 'bridge')) . '</label>';
        echo '<input type="number" class="form-control" name="limit" value="50" min="1" max="500">';
        echo '<div class="form-text">' . self::h(__('Max records per run.', 'bridge')) . '</div>';
        echo '</div>';

        // Options
        echo '<div class="col-12"><hr class="my-1"></div>';
        echo '<div class="col-12"><p class="fw-semibold mb-1">' . self::h(__('Content', 'bridge')) . '</p></div>';

        echo '<div class="col-12">';
        echo '<div class="form-check mb-2">';
        echo '<input class="form-check-input" type="checkbox" id="inc_comments" name="include_comments" value="1" checked>';
        echo '<label class="form-check-label" for="inc_comments">';
        echo '<i class="ti ti-message me-1"></i>' . self::h(__('Comments → Followups', 'bridge'));
        echo '</label>';
        echo '</div>';
        echo '<div class="form-check">';
        echo '<input class="form-check-input" type="checkbox" id="inc_attachments" name="include_attachments" value="1">';
        echo '<label class="form-check-label" for="inc_attachments">';
        echo '<i class="ti ti-paperclip me-1"></i>' . self::h(__('Attachments → Documents', 'bridge'));
        echo '<span class="text-muted small ms-1">' . self::h(__('(slower — downloads files)', 'bridge')) . '</span>';
        echo '</label>';
        echo '</div>';
        echo '</div>';

        // Actions
        echo '<div class="col-12 d-flex gap-2 mt-2">';
        echo '<button type="submit" name="action" value="migrate" class="btn btn-primary">';
        echo '<i class="ti ti-database-import me-1"></i>' . self::h(__('Migrate now', 'bridge'));
        echo '</button>';
        echo '<button type="submit" name="action" value="dryrun" class="btn btn-outline-warning">';
        echo '<i class="ti ti-list-check me-1"></i>' . self::h(__('Dry-run preview', 'bridge'));
        echo '</button>';
        echo '</div>';

        echo '</div>'; // row
        echo '</form>';
        echo '</div>';
    }

    public static function showResult(
        Connection      $connection,
        MigrationResult $result,
        string          $resourceType,
        string          $historyUrl
    ): void {
        $id = (int) $connection->fields['id'];

        echo '<div class="container-fluid p-3" style="max-width:860px">';

        $isDry = $result->isDryRun;
        $title = $isDry ? __('Dry-run result', 'bridge') : __('Migration complete', 'bridge');
        $icon  = $isDry ? 'list-check text-warning' : 'circle-check text-success';

        echo '<div class="d-flex align-items-center justify-content-between mb-3">';
        echo '<h4 class="m-0"><i class="ti ti-' . $icon . ' me-2"></i>' . self::h($title) . '</h4>';
        echo '<div class="d-flex gap-2">';
        if (!$isDry) {
            echo '<a class="btn btn-outline-secondary btn-sm" href="' . self::h($historyUrl . '?id=' . $id) . '">';
            echo '<i class="ti ti-history me-1"></i>' . self::h(__('History', 'bridge')) . '</a>';
        }
        echo '<a class="btn btn-outline-secondary btn-sm" href="' . self::h(Connection::getConfigURL($id)) . '">';
        echo '<i class="ti ti-arrow-left me-1"></i>' . self::h(__('Back', 'bridge')) . '</a>';
        echo '</div>';
        echo '</div>';

        // Summary cards
        echo '<div class="row g-2 mb-3">';
        self::statCard('circle-check', 'success', count($result->created), $isDry ? __('Would create', 'bridge') : __('Created', 'bridge'));
        self::statCard('circle-x',     'danger',  count($result->failed),  __('Failed', 'bridge'));
        self::statCard('minus-circle', 'secondary', count($result->skipped), __('Skipped (already migrated)', 'bridge'));
        echo '</div>';

        if ($isDry) {
            echo '<div class="alert alert-warning">';
            echo '<i class="ti ti-alert-triangle me-1"></i>';
            echo self::h(__('Dry-run — nothing was written to GLPI.', 'bridge'));
            echo '</div>';
        }

        // Created
        if (!empty($result->created)) {
            echo '<div class="card mb-3">';
            echo '<div class="card-header fw-semibold text-success"><i class="ti ti-circle-check me-1"></i>';
            echo self::h($isDry ? __('Would create', 'bridge') : __('Created tickets', 'bridge')) . '</div>';
            echo '<div class="table-responsive"><table class="table table-sm mb-0">';
            echo '<thead class="table-light"><tr><th>#</th><th>' . self::h(__('Name', 'bridge')) . '</th>';
            if (!$isDry) echo '<th>GLPI ID</th>';
            echo '</tr></thead><tbody>';
            foreach ($result->created as $r) {
                echo '<tr><td>' . self::h($r['number']) . '</td>';
                echo '<td>' . self::h($r['name']) . '</td>';
                if (!$isDry) {
                    $ticketUrl = \Ticket::getFormURLWithID((int) $r['tickets_id']);
                    echo '<td><a href="' . self::h($ticketUrl) . '" target="_blank">#' . (int) $r['tickets_id'] . '</a></td>';
                }
                echo '</tr>';
            }
            echo '</tbody></table></div></div>';
        }

        // Failed
        if (!empty($result->failed)) {
            echo '<div class="card mb-3">';
            echo '<div class="card-header fw-semibold text-danger"><i class="ti ti-circle-x me-1"></i>' . self::h(__('Failed', 'bridge')) . '</div>';
            echo '<div class="table-responsive"><table class="table table-sm mb-0">';
            echo '<thead class="table-light"><tr><th>#</th><th>' . self::h(__('Name', 'bridge')) . '</th><th>' . self::h(__('Error', 'bridge')) . '</th></tr></thead><tbody>';
            foreach ($result->failed as $r) {
                echo '<tr class="table-danger"><td>' . self::h($r['number']) . '</td>';
                echo '<td>' . self::h($r['name']) . '</td>';
                echo '<td class="small">' . self::h($r['reason']) . '</td></tr>';
            }
            echo '</tbody></table></div></div>';
        }

        echo '</div>';
    }

    private static function statCard(string $icon, string $color, int $value, string $label): void
    {
        echo '<div class="col-md-4">';
        echo '<div class="card border-' . $color . '">';
        echo '<div class="card-body py-2 d-flex align-items-center gap-3">';
        echo '<i class="ti ti-' . $icon . ' text-' . $color . '" style="font-size:1.8rem"></i>';
        echo '<div><div class="fw-bold fs-4">' . $value . '</div>';
        echo '<div class="text-muted small">' . self::h($label) . '</div></div>';
        echo '</div></div></div>';
    }

    private static function h(mixed $v): string
    {
        return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    }
}

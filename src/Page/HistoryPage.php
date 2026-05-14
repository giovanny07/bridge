<?php

namespace GlpiPlugin\Bridge\Page;

use GlpiPlugin\Bridge\Connection;
use GlpiPlugin\Bridge\Migration\MigrationRecord;

class HistoryPage
{
    public static function show(Connection $connection, string $migrateUrl, string $purgeUrl): void
    {
        $id           = (int) $connection->fields['id'];
        $filterStatus = (string) ($_GET['status'] ?? '');
        $filterType   = (string) ($_GET['source_type'] ?? '');

        $summary = MigrationRecord::getSummary($id);
        $records = MigrationRecord::getHistory($id, [
            'status'      => $filterStatus,
            'source_type' => $filterType,
        ], 200);

        echo '<div class="container-fluid p-3">';

        // Header
        echo '<div class="d-flex align-items-center justify-content-between mb-3">';
        echo '<h4 class="m-0"><i class="ti ti-history me-2"></i>' . self::h(__('Migration history', 'bridge')) . '</h4>';
        echo '<div class="d-flex gap-2">';
        echo '<a class="btn btn-outline-primary btn-sm" href="' . self::h($migrateUrl . '?id=' . $id) . '">';
        echo '<i class="ti ti-database-import me-1"></i>' . self::h(__('Migrate', 'bridge')) . '</a>';
        echo '<a class="btn btn-outline-secondary btn-sm" href="' . self::h(Connection::getConfigURL($id)) . '">';
        echo '<i class="ti ti-arrow-left me-1"></i>' . self::h(__('Back', 'bridge')) . '</a>';
        echo '</div>';
        echo '</div>';

        echo '<p class="text-muted">';
        echo '<i class="ti ti-plug me-1"></i>' . self::h($connection->fields['name']);
        echo '</p>';

        // Summary
        echo '<div class="row g-2 mb-3">';
        self::statCard('database', 'primary',   $summary['total'],   __('Total migrated', 'bridge'));
        self::statCard('circle-check', 'success', $summary['success'] ?? 0, __('Success', 'bridge'));
        self::statCard('circle-x', 'danger',    $summary['failed'] ?? 0,  __('Failed', 'bridge'));
        echo '</div>';

        // Purge actions
        echo '<div class="card mb-3">';
        echo '<div class="card-header fw-semibold text-danger"><i class="ti ti-trash me-1"></i>' . self::h(__('Purge', 'bridge')) . '</div>';
        echo '<div class="card-body">';
        echo '<p class="text-muted small mb-2">' . self::h(__('Purged records will be re-processed on the next migration run.', 'bridge')) . '</p>';
        echo '<div class="d-flex flex-wrap gap-2">';

        foreach ([
            ['purgeFailed', 'btn-outline-warning', 'ti-refresh', __('Retry failed (purge failed records)', 'bridge')],
            ['purgeAll',    'btn-outline-danger',  'ti-trash',   __('Purge all records for this connection', 'bridge')],
        ] as [$action, $btnClass, $icon, $label]) {
            echo '<form method="post" action="' . self::h($purgeUrl) . '">';
            echo \Html::hidden('_glpi_csrf_token', ['value' => \Session::getNewCSRFToken()]);
            echo \Html::hidden('id',     ['value' => $id]);
            echo \Html::hidden('action', ['value' => $action]);
            $confirm = addslashes(self::h(__('Are you sure?', 'bridge')));
            echo '<button type="submit" class="btn btn-sm ' . $btnClass . '" onclick="return confirm(\'' . $confirm . '\')">';
            echo '<i class="ti ' . $icon . ' me-1"></i>' . self::h($label);
            echo '</button>';
            echo '</form>';
        }

        echo '</div>';
        echo '</div>';
        echo '</div>';

        // Filter bar
        echo '<div class="d-flex gap-2 mb-2 align-items-center">';
        echo '<span class="text-muted small">' . self::h(__('Filter:', 'bridge')) . '</span>';
        foreach (['' => __('All', 'bridge'), MigrationRecord::STATUS_SUCCESS => __('Success', 'bridge'), MigrationRecord::STATUS_FAILED => __('Failed', 'bridge'), MigrationRecord::STATUS_SKIPPED => __('Skipped', 'bridge')] as $val => $lbl) {
            $active = $filterStatus === $val ? ' btn-primary' : ' btn-outline-secondary';
            $url    = '?id=' . $id . ($val !== '' ? '&status=' . urlencode($val) : '');
            echo '<a class="btn btn-sm' . $active . '" href="' . self::h($url) . '">' . self::h($lbl) . '</a>';
        }
        echo '</div>';

        // Records table
        echo '<div class="card">';
        echo '<div class="table-responsive">';
        echo '<table class="table table-sm table-hover align-middle mb-0" style="font-size:.82rem">';
        echo '<thead class="table-light"><tr>';
        echo '<th>' . self::h(__('Date', 'bridge')) . '</th>';
        echo '<th>' . self::h(__('Type', 'bridge')) . '</th>';
        echo '<th>' . self::h(__('Source #', 'bridge')) . '</th>';
        echo '<th>' . self::h(__('Status', 'bridge')) . '</th>';
        echo '<th>GLPI</th>';
        echo '<th>' . self::h(__('Error', 'bridge')) . '</th>';
        echo '</tr></thead><tbody>';

        if (empty($records)) {
            echo '<tr><td colspan="6" class="text-center text-muted py-4">' . self::h(__('No records yet.', 'bridge')) . '</td></tr>';
        }

        foreach ($records as $r) {
            $badgeClass = match ($r['status']) {
                'success' => 'bg-success',
                'failed'  => 'bg-danger',
                default   => 'bg-secondary',
            };

            echo '<tr>';
            echo '<td class="text-nowrap">' . self::h($r['migrated_at']) . '</td>';
            echo '<td><span class="badge bg-secondary bg-opacity-75">' . self::h($r['source_type']) . '</span></td>';
            echo '<td>#' . self::h($r['source_number']) . '</td>';
            echo '<td><span class="badge ' . $badgeClass . '">' . self::h($r['status']) . '</span></td>';
            echo '<td>';
            if ((int) $r['tickets_id'] > 0) {
                $url = \Ticket::getFormURLWithID((int) $r['tickets_id']);
                echo '<a href="' . self::h($url) . '" target="_blank">#' . (int) $r['tickets_id'] . '</a>';
            } else {
                echo '—';
            }
            echo '</td>';
            echo '<td class="text-danger small">' . self::h((string) ($r['error_message'] ?? '')) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table></div></div>';
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

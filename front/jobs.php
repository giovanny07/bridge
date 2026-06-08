<?php

use GlpiPlugin\Bridge\Connection;
use GlpiPlugin\Bridge\Migration\BridgeJob;

Session::checkRight('config', READ);

$id         = (int) ($_REQUEST['id'] ?? 0);
$connection = new Connection();

if (!$id || !$connection->getFromDB($id)) {
    Session::addMessageAfterRedirect(__('Connection not found.', 'bridge'), false, ERROR);
    Html::redirect(Connection::getConfigURL());
}

Html::header(__('Migration jobs', 'bridge'), '', 'config', 'plugins');

$jobs       = BridgeJob::getForConnection($id, 100);
$_frontDir  = Connection::getPluginBaseURL() . '/front';
$jobUrl     = $_frontDir . '/job_status.php';
$migrateUrl = $_frontDir . '/migrate.php';

$statusClass = [
    BridgeJob::STATUS_PENDING      => 'bg-secondary',
    BridgeJob::STATUS_RUNNING      => 'bg-primary',
    BridgeJob::STATUS_COMPLETED    => 'bg-success',
    BridgeJob::STATUS_FAILED       => 'bg-danger',
    BridgeJob::STATUS_CANCELLED    => 'bg-warning text-dark',
    BridgeJob::STATUS_ROLLED_BACK  => 'bg-dark',
];
$statusLabel = [
    BridgeJob::STATUS_PENDING      => __('Pending',     'bridge'),
    BridgeJob::STATUS_RUNNING      => __('Running',     'bridge'),
    BridgeJob::STATUS_COMPLETED    => __('Completed',   'bridge'),
    BridgeJob::STATUS_FAILED       => __('Failed',      'bridge'),
    BridgeJob::STATUS_CANCELLED    => __('Cancelled',   'bridge'),
    BridgeJob::STATUS_ROLLED_BACK  => __('Rolled back', 'bridge'),
];

$h = static fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

echo '<div class="container-fluid py-3 px-4">';

// Header
echo '<div class="d-flex align-items-center justify-content-between mb-4">';
echo '<div>';
echo '<h4 class="mb-0"><i class="ti ti-list-details me-2 text-primary"></i>' . $h(__('Migration jobs', 'bridge')) . '</h4>';
echo '<div class="text-muted small mt-1"><i class="ti ti-plug me-1"></i><strong>' . $h($connection->fields['name']) . '</strong></div>';
echo '</div>';
echo '<div class="d-flex gap-2">';
echo '<a class="btn btn-sm btn-primary" href="' . $h($migrateUrl . '?id=' . $id) . '">';
echo '<i class="ti ti-database-import me-1"></i>' . $h(__('New migration', 'bridge')) . '</a>';
echo '<a class="btn btn-sm btn-outline-secondary" href="' . $h(Connection::getConfigURL($id)) . '">';
echo '<i class="ti ti-arrow-left me-1"></i>' . $h(__('Back', 'bridge')) . '</a>';
echo '</div>';
echo '</div>';

if (empty($jobs)) {
    echo '<div class="alert alert-light border">';
    echo '<i class="ti ti-info-circle me-1"></i>' . $h(__('No migration jobs yet. Click "New migration" to start.', 'bridge'));
    echo '</div>';
} else {
    echo '<div class="card border-0 shadow-sm">';
    echo '<div class="table-responsive">';
    echo '<table class="table table-hover table-sm align-middle mb-0" style="font-size:.85rem">';
    echo '<thead class="table-light"><tr>';
    echo '<th>#</th><th>' . $h(__('Type', 'bridge')) . '</th>';
    echo '<th>' . $h(__('Status', 'bridge')) . '</th>';
    echo '<th>' . $h(__('Created', 'bridge')) . '</th>';
    echo '<th class="text-success">' . $h(__('Created', 'bridge')) . '</th>';
    echo '<th class="text-danger">' . $h(__('Failed', 'bridge')) . '</th>';
    echo '<th>' . $h(__('Scanned', 'bridge')) . '</th>';
    echo '<th>' . $h(__('Chunks', 'bridge')) . '</th>';
    echo '<th>' . $h(__('Finished', 'bridge')) . '</th>';
    echo '<th></th>';
    echo '</tr></thead><tbody>';

    foreach ($jobs as $row) {
        $stats  = json_decode($row['stats_json'] ?? '{}', true) ?? [];
        $status = (string) ($row['status'] ?? 'pending');
        $rowCls = $status === BridgeJob::STATUS_RUNNING ? ' class="table-primary bg-opacity-25"' : '';

        echo '<tr' . $rowCls . '>';
        echo '<td class="text-muted">' . (int) $row['id'] . '</td>';
        echo '<td><span class="badge bg-secondary bg-opacity-75">' . $h($row['resource_type'] ?? '') . '</span></td>';
        echo '<td><span class="badge ' . ($statusClass[$status] ?? 'bg-secondary') . '">' . $h($statusLabel[$status] ?? $status) . '</span></td>';
        echo '<td class="text-muted">' . $h(substr($row['created_at'] ?? '', 0, 16)) . '</td>';
        echo '<td class="text-success fw-semibold">' . (int) ($stats['created']   ?? 0) . '</td>';
        echo '<td class="text-danger">'              . (int) ($stats['failed']    ?? 0) . '</td>';
        echo '<td class="text-muted">'               . (int) ($stats['scanned']   ?? 0) . '</td>';
        echo '<td class="text-muted">'               . (int) ($stats['chunks']    ?? 0) . '</td>';
        echo '<td class="text-muted">' . $h(substr($row['finished_at'] ?? '—', 0, 16)) . '</td>';
        echo '<td class="text-end text-nowrap">';
        echo '<a class="btn btn-xs btn-outline-secondary btn-sm" href="' . $h($jobUrl . '?job_id=' . (int) $row['id']) . '">';
        echo '<i class="ti ti-eye me-1"></i>' . $h(__('View', 'bridge')) . '</a>';
        echo '</td>';
        echo '</tr>';
    }

    echo '</tbody></table></div></div>';
}

echo '</div>';
Html::footer();

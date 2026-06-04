<?php

namespace GlpiPlugin\Bridge\Page;

use GlpiPlugin\Bridge\Connection;
use GlpiPlugin\Bridge\Migration\MigrationRecord;
use GlpiPlugin\Bridge\Migration\BridgeJob;

class HistoryPage
{
    private const PER_PAGE = 50;

    public static function show(Connection $connection, string $migrateUrl, string $purgeUrl): void
    {
        $id           = (int) $connection->fields['id'];
        $filterStatus = (string) ($_GET['status'] ?? '');
        $search       = trim((string) ($_GET['q'] ?? ''));
        $page         = max(1, (int) ($_GET['page'] ?? 1));

        $filters = ['status' => $filterStatus, 'search' => $search];
        $total   = MigrationRecord::countHistory($id, $filters);
        $pages   = max(1, (int) ceil($total / self::PER_PAGE));
        $page    = min($page, $pages);
        $offset  = ($page - 1) * self::PER_PAGE;

        $summary = MigrationRecord::getSummary($id);
        $records = MigrationRecord::getHistory($id, $filters, self::PER_PAGE, $offset);

        echo '<div class="container-fluid py-3 px-4">';

        // ── Header ────────────────────────────────────────────────────────
        echo '<div class="d-flex align-items-center justify-content-between mb-4">';
        echo '<div>';
        echo '<h4 class="mb-0"><i class="ti ti-history me-2 text-primary"></i>' . self::h(__('Migration history', 'bridge')) . '</h4>';
        echo '<div class="text-muted small mt-1"><i class="ti ti-plug me-1"></i><strong>' . self::h($connection->fields['name']) . '</strong></div>';
        echo '</div>';
        echo '<div class="d-flex gap-2">';
        $jobsUrl = \Plugin::getWebDir('bridge', true) . '/front/jobs.php';
        echo '<a class="btn btn-sm btn-outline-secondary" href="' . self::h($jobsUrl . '?id=' . $id) . '"><i class="ti ti-list-details me-1"></i>' . self::h(__('Jobs', 'bridge')) . '</a>';
        echo '<a class="btn btn-sm btn-outline-primary" href="' . self::h($migrateUrl . '?id=' . $id) . '"><i class="ti ti-database-import me-1"></i>' . self::h(__('Migrate', 'bridge')) . '</a>';
        echo '<a class="btn btn-sm btn-outline-secondary" href="' . self::h(Connection::getConfigURL($id)) . '"><i class="ti ti-arrow-left me-1"></i>' . self::h(__('Back', 'bridge')) . '</a>';
        echo '</div>';
        echo '</div>';

        // ── Active job banner ────────────────────────────────────────────
        $jobSummary = BridgeJob::getConnectionSummary($id);
        if ($jobSummary['active_job_id'] !== null) {
            $jobUrl     = \Plugin::getWebDir('bridge', true) . '/front/job_status.php?job_id=' . $jobSummary['active_job_id'];
            $isRunning  = ($jobSummary['last_status'] === BridgeJob::STATUS_RUNNING);
            $label      = $isRunning ? __('A migration is currently running', 'bridge') : __('A migration job is pending', 'bridge');
            echo '<div class="alert alert-primary d-flex align-items-center justify-content-between gap-3 mb-4">';
            echo '<span><i class="ti ti-player-play me-1"></i>' . self::h($label) . ' — ' . self::h(__('this list updates automatically.', 'bridge')) . '</span>';
            echo '<a class="btn btn-sm btn-primary" href="' . self::h($jobUrl) . '"><i class="ti ti-eye me-1"></i>' . self::h(__('View progress', 'bridge')) . '</a>';
            echo '</div>';
        }

        // ── Summary cards ─────────────────────────────────────────────────
        echo '<div class="row g-3 mb-4">';
        self::statCard('database',     'primary',   $summary['total'],   __('Total',   'bridge'));
        self::statCard('circle-check', 'success',   $summary['success'] ?? 0, __('Success', 'bridge'));
        self::statCard('alert-triangle','warning',  $summary['warning'] ?? 0, __('With warnings', 'bridge'));
        self::statCard('circle-x',     'danger',    $summary['failed']  ?? 0, __('Failed',  'bridge'));
        echo '</div>';

        // ── Search bar ───────────────────────────────────────────────────
        $baseUrl = '?id=' . $id;
        echo '<form method="get" action="" class="mb-3">';
        echo \Html::hidden('id', ['value' => $id]);
        if ($filterStatus !== '') {
            echo \Html::hidden('status', ['value' => $filterStatus]);
        }
        echo '<div class="input-group input-group-sm" style="max-width:360px">';
        echo '<span class="input-group-text"><i class="ti ti-search"></i></span>';
        echo '<input type="text" class="form-control" name="q" value="' . self::h($search) . '" '
           . 'placeholder="' . self::h(__('Search by ticket # or source ID…', 'bridge')) . '" autocomplete="off">';
        if ($search !== '') {
            echo '<a class="btn btn-outline-secondary" href="' . self::h($baseUrl . ($filterStatus !== '' ? '&status=' . urlencode($filterStatus) : '')) . '" title="' . self::h(__('Clear', 'bridge')) . '">';
            echo '<i class="ti ti-x"></i></a>';
        } else {
            echo '<button type="submit" class="btn btn-outline-secondary">' . self::h(__('Search', 'bridge')) . '</button>';
        }
        echo '</div>';
        echo '</form>';

        // ── Bulk action form (wraps table) ────────────────────────────────
        echo '<form method="post" action="' . self::h($purgeUrl) . '" id="bridge-history-form">';
        echo \Html::hidden('_glpi_csrf_token', ['value' => \Session::getNewCSRFToken()]);
        echo \Html::hidden('id',     ['value' => $id]);
        echo \Html::hidden('action', ['value' => 'purgeSelected', 'id' => 'bridge-action-input']);

        // ── Toolbar: filters + bulk actions ───────────────────────────────
        echo '<div class="d-flex flex-wrap gap-2 align-items-center justify-content-between mb-2">';

        // Filter pills (preserve search query)
        $qParam = $search !== '' ? '&q=' . urlencode($search) : '';
        echo '<div class="d-flex flex-wrap gap-1 align-items-center">';
        $filterDefs = [
            ''        => [__('All', 'bridge'),           'secondary'],
            'success' => [__('Success', 'bridge'),        'success'],
            'warning' => [__('With warnings', 'bridge'),  'warning'],
            'failed'  => [__('Failed', 'bridge'),         'danger'],
            'skipped' => [__('Skipped', 'bridge'),        'secondary'],
        ];
        foreach ($filterDefs as $val => [$lbl, $color]) {
            $active = ($filterStatus === $val);
            $url    = '?id=' . $id . ($val !== '' ? '&status=' . urlencode($val) : '') . $qParam;
            $cls    = $active ? "btn-$color" : "btn-outline-$color";
            echo '<a class="btn btn-sm ' . $cls . '" href="' . self::h($url) . '">' . self::h($lbl) . '</a>';
        }
        // Results count
        $from = $total > 0 ? $offset + 1 : 0;
        $to   = min($offset + self::PER_PAGE, $total);
        echo '<span class="text-muted small ms-2">' . sprintf(self::h(__('%d–%d of %d', 'bridge')), $from, $to, $total) . '</span>';
        echo '</div>';

        // Bulk action buttons
        echo '<div class="d-flex gap-2 align-items-center">';
        echo '<span class="text-muted small" id="bridge-sel-count" style="display:none"></span>';
        echo '<button type="submit" class="btn btn-sm btn-outline-danger" id="bridge-purge-sel" style="display:none" data-bridge-confirm="' . self::h(__('Purge selected records? They will be re-processed on the next run.', 'bridge')) . '">';
        echo '<i class="ti ti-trash me-1"></i>' . self::h(__('Purge selected', 'bridge'));
        echo '</button>';

        // Global purge actions
        foreach ([
            ['purgeFailed', 'btn-outline-warning', 'ti-refresh', __('Retry failed', 'bridge')],
            ['purgeAll',    'btn-outline-danger',  'ti-trash',   __('Purge all', 'bridge')],
        ] as [$act, $btnClass, $icon, $lbl]) {
            echo '<button type="button" class="btn btn-sm ' . $btnClass . '" '
               . 'data-bridge-history-action="' . self::h($act) . '" '
               . 'data-confirm="' . self::h(__('Are you sure?', 'bridge')) . '">';
            echo '<i class="ti ' . $icon . ' me-1"></i>' . self::h($lbl);
            echo '</button>';
        }
        echo '</div>';
        echo '</div>'; // toolbar

        // ── Records table ─────────────────────────────────────────────────
        echo '<div class="card border-0 shadow-sm">';
        echo '<div class="table-responsive">';
        echo '<table class="table table-sm table-hover align-middle mb-0" style="font-size:.82rem">';
        echo '<thead class="table-light"><tr>';
        echo '<th style="width:2rem"><input type="checkbox" class="form-check-input" id="bridge-check-all" title="' . self::h(__('Select all', 'bridge')) . '"></th>';
        echo '<th>' . self::h(__('Date', 'bridge')) . '</th>';
        echo '<th>' . self::h(__('Source #', 'bridge')) . '</th>';
        echo '<th>' . self::h(__('Status', 'bridge')) . '</th>';
        echo '<th>GLPI</th>';
        echo '<th>' . self::h(__('Notes', 'bridge')) . '</th>';
        echo '</tr></thead><tbody>';

        if (empty($records)) {
            echo '<tr><td colspan="6" class="text-center text-muted py-4">';
            echo '<i class="ti ti-database-off me-1"></i>' . self::h(__('No records yet.', 'bridge'));
            echo '</td></tr>';
        }

        foreach ($records as $r) {
            $hasWarning = ($r['status'] === MigrationRecord::STATUS_SUCCESS && !empty($r['error_message']));

            [$badgeClass, $badgeLabel] = match (true) {
                $hasWarning                              => ['bg-warning text-dark', __('partial', 'bridge')],
                $r['status'] === 'success'               => ['bg-success',           __('success',  'bridge')],
                $r['status'] === 'failed'                => ['bg-danger',            __('failed',   'bridge')],
                default                                  => ['bg-secondary',         $r['status']],
            };

            $rowClass = $hasWarning ? ' class="table-warning bg-opacity-25"' : '';

            echo '<tr' . $rowClass . '>';
            echo '<td><input type="checkbox" class="form-check-input bridge-row-check" name="ids[]" value="' . (int) $r['id'] . '"></td>';
            echo '<td class="text-nowrap text-muted">' . self::h(substr($r['migrated_at'], 0, 16)) . '</td>';
            echo '<td><span class="font-monospace">#' . self::h($r['source_number']) . '</span></td>';
            echo '<td><span class="badge ' . $badgeClass . '">' . self::h($badgeLabel) . '</span></td>';
            echo '<td>';
            if ((int) $r['tickets_id'] > 0) {
                $glpiClass = match ($r['source_type']) {
                    'problems' => 'Problem',
                    'changes'  => 'Change',
                    default    => 'Ticket',
                };
                $ticketUrl = $glpiClass::getFormURLWithID((int) $r['tickets_id']);
                echo '<a href="' . self::h($ticketUrl) . '" target="_blank" class="text-decoration-none">';
                echo '#' . (int) $r['tickets_id'] . ' <i class="ti ti-external-link" style="font-size:.7rem"></i>';
                echo '</a>';
            } else {
                echo '<span class="text-muted">—</span>';
            }
            echo '</td>';
            echo '<td>';
            if (!empty($r['error_message'])) {
                $msgClass = $hasWarning ? 'text-warning-emphasis' : 'text-danger';
                $icon     = $hasWarning ? 'ti-alert-triangle' : 'ti-circle-x';
                echo '<span class="' . $msgClass . ' small"><i class="ti ' . $icon . ' me-1"></i>' . self::h($r['error_message']) . '</span>';
            }
            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody></table></div></div>';
        echo '</form>';

        // ── Pagination ────────────────────────────────────────────────────
        if ($pages > 1) {
            $statusParam = $filterStatus !== '' ? '&status=' . urlencode($filterStatus) : '';
            echo '<nav class="mt-3 d-flex justify-content-center align-items-center gap-2">';

            // Prev
            if ($page > 1) {
                $url = '?id=' . $id . $statusParam . $qParam . '&page=' . ($page - 1);
                echo '<a class="btn btn-sm btn-outline-secondary" href="' . self::h($url) . '"><i class="ti ti-chevron-left"></i></a>';
            } else {
                echo '<button class="btn btn-sm btn-outline-secondary" disabled><i class="ti ti-chevron-left"></i></button>';
            }

            // Page numbers (show window of 5 around current)
            $windowStart = max(1, $page - 2);
            $windowEnd   = min($pages, $page + 2);
            if ($windowStart > 1) {
                echo '<a class="btn btn-sm btn-outline-secondary" href="' . self::h('?id=' . $id . $statusParam . $qParam . '&page=1') . '">1</a>';
                if ($windowStart > 2) echo '<span class="text-muted px-1">…</span>';
            }
            for ($p = $windowStart; $p <= $windowEnd; $p++) {
                $url = '?id=' . $id . $statusParam . $qParam . '&page=' . $p;
                $cls = $p === $page ? 'btn-primary' : 'btn-outline-secondary';
                echo '<a class="btn btn-sm ' . $cls . '" href="' . self::h($url) . '">' . $p . '</a>';
            }
            if ($windowEnd < $pages) {
                if ($windowEnd < $pages - 1) echo '<span class="text-muted px-1">…</span>';
                echo '<a class="btn btn-sm btn-outline-secondary" href="' . self::h('?id=' . $id . $statusParam . $qParam . '&page=' . $pages) . '">' . $pages . '</a>';
            }

            // Next
            if ($page < $pages) {
                $url = '?id=' . $id . $statusParam . $qParam . '&page=' . ($page + 1);
                echo '<a class="btn btn-sm btn-outline-secondary" href="' . self::h($url) . '"><i class="ti ti-chevron-right"></i></a>';
            } else {
                echo '<button class="btn btn-sm btn-outline-secondary" disabled><i class="ti ti-chevron-right"></i></button>';
            }

            echo '</nav>';
        }

        echo '</div>';
    }

    private static function statCard(string $icon, string $color, int $value, string $label): void
    {
        echo '<div class="col-6 col-md-3">';
        echo '<div class="card border-0 shadow-sm h-100">';
        echo '<div class="card-body py-3 d-flex align-items-center gap-3">';
        echo '<div class="rounded-circle d-flex align-items-center justify-content-center text-' . $color . '" style="width:2.8rem;height:2.8rem;background:var(--bs-' . $color . '-bg-subtle,rgba(0,0,0,.06))">';
        echo '<i class="ti ti-' . $icon . '" style="font-size:1.2rem"></i>';
        echo '</div>';
        echo '<div><div class="fw-bold fs-4 lh-1">' . $value . '</div><div class="text-muted small mt-1">' . self::h($label) . '</div></div>';
        echo '</div></div></div>';
    }

    private static function h(mixed $v): string
    {
        return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    }
}

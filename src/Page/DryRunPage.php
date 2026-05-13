<?php

namespace GlpiPlugin\Bridge\Page;

use GlpiPlugin\Bridge\Connection;
use GlpiPlugin\Bridge\Migration\MappedIncident;

class DryRunPage
{
    /**
     * @param MappedIncident[] $results
     */
    public static function show(Connection $connection, array $results, int $total): void
    {
        $ok          = count(array_filter($results, fn($r) => $r->status === 'ok'));
        $partial     = count(array_filter($results, fn($r) => $r->status === 'partial'));
        $unresolved  = count(array_filter($results, fn($r) => $r->status === 'unresolved'));
        $sample      = count($results);

        echo '<div class="container-fluid p-3">';

        // ── Header ──────────────────────────────────────────────────────
        echo '<div class="d-flex align-items-center justify-content-between mb-3">';
        echo '<div>';
        echo '<h4 class="m-0"><i class="ti ti-list-check me-2 text-warning"></i>';
        echo self::h(__('Dry-run', 'bridge')) . '</h4>';
        echo '<div class="text-muted small mt-1">';
        echo '<i class="ti ti-plug me-1"></i>' . self::h($connection->fields['name']);
        echo ' &mdash; ' . self::h(sprintf(__('sample of %d / %s total incidents', 'bridge'), $sample, number_format($total)));
        echo '</div>';
        echo '</div>';
        echo '<a class="btn btn-outline-secondary btn-sm" href="' . self::h(Connection::getConfigURL((int) $connection->fields['id'])) . '">';
        echo '<i class="ti ti-arrow-left me-1"></i>' . self::h(__('Back', 'bridge'));
        echo '</a>';
        echo '</div>';

        // ── Summary ──────────────────────────────────────────────────────
        echo '<div class="row g-2 mb-3">';
        self::showStatCard('circle-check',  'success', (string) $ok,         __('Fully resolved', 'bridge'));
        self::showStatCard('alert-triangle', 'warning', (string) $partial,    __('Partial (fallback used)', 'bridge'));
        self::showStatCard('circle-x',      'danger',  (string) $unresolved, __('Unresolved', 'bridge'));
        echo '</div>';

        // ── Table ────────────────────────────────────────────────────────
        echo '<div class="card">';
        echo '<div class="card-header fw-semibold small">' . self::h(__('Incident resolution preview', 'bridge')) . '</div>';
        echo '<div class="table-responsive">';
        echo '<table class="table table-sm table-hover align-middle mb-0" style="font-size:.82rem">';
        echo '<thead class="table-light"><tr>';
        echo '<th>#</th>';
        echo '<th>' . self::h(__('Incident', 'bridge')) . '</th>';
        echo '<th>' . self::h(__('Entity', 'bridge')) . '</th>';
        echo '<th>' . self::h(__('Category', 'bridge')) . '</th>';
        echo '<th>' . self::h(__('Group / Assignee', 'bridge')) . '</th>';
        echo '<th>' . self::h(__('Requester', 'bridge')) . '</th>';
        echo '<th>' . self::h(__('Status', 'bridge')) . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($results as $mapped) {
            $t   = $mapped->ticket;
            $inc = $mapped->original;
            self::showRow($mapped, $t, $inc);
        }

        echo '</tbody></table>';
        echo '</div>';
        echo '</div>';

        // ── Warnings legend ──────────────────────────────────────────────
        $allWarnings = array_merge(...array_map(fn($r) => $r->warnings, $results));
        $uniqueWarnings = array_unique($allWarnings);

        if ($uniqueWarnings !== []) {
            echo '<div class="card mt-3">';
            echo '<div class="card-header fw-semibold small text-warning">';
            echo '<i class="ti ti-alert-triangle me-1"></i>' . self::h(__('Unresolved names (configure fallbacks or check spelling)', 'bridge'));
            echo '</div>';
            echo '<div class="card-body p-2">';
            echo '<ul class="mb-0 small">';
            foreach ($uniqueWarnings as $w) {
                echo '<li>' . self::h($w) . '</li>';
            }
            echo '</ul>';
            echo '</div>';
            echo '</div>';
        }

        echo '</div>';
    }

    private static function showRow(MappedIncident $mapped, array $t, array $inc): void
    {
        $rowClass = match ($mapped->status) {
            'ok'         => '',
            'partial'    => ' class="table-warning"',
            'unresolved' => ' class="table-danger"',
        };

        $statusBadge = match ($mapped->status) {
            'ok'         => '<span class="badge bg-success">OK</span>',
            'partial'    => '<span class="badge bg-warning text-dark">Partial</span>',
            'unresolved' => '<span class="badge bg-danger">!</span>',
        };

        $entityText   = $t['entities_id']       ? '<span class="text-success">ID ' . $t['entities_id'] . '</span>'       : '<span class="text-danger">—</span>';
        $categoryText = $t['itilcategories_id']  ? '<span class="text-success">ID ' . $t['itilcategories_id'] . '</span>' : '<span class="text-warning">—</span>';

        $assigneeText = '—';
        if ($t['_users_id_assign']) {
            $assigneeText = '<span class="text-success">user ' . $t['_users_id_assign'] . '</span>';
        } elseif ($t['_groups_id_assign']) {
            $assigneeText = '<span class="text-success">group ' . $t['_groups_id_assign'] . '</span>';
        } else {
            $assigneeText = '<span class="text-warning">—</span>';
        }

        $requesterText = $t['_users_id_requester']
            ? '<span class="text-success">ID ' . $t['_users_id_requester'] . '</span>'
            : '<span class="text-warning">—</span>';

        echo '<tr' . $rowClass . '>';
        echo '<td class="text-muted">' . self::h((string) ($inc['number'] ?? '')) . '</td>';
        echo '<td style="max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="' . self::h((string) ($inc['name'] ?? '')) . '">';
        echo self::h(mb_substr((string) ($inc['name'] ?? ''), 0, 60));
        echo '</td>';
        echo '<td>' . $entityText . '</td>';
        echo '<td>' . $categoryText . '</td>';
        echo '<td>' . $assigneeText . '</td>';
        echo '<td>' . $requesterText . '</td>';
        echo '<td>' . $statusBadge . '</td>';
        echo '</tr>';

        // Inline warning rows
        if ($mapped->warnings !== []) {
            echo '<tr' . $rowClass . '>';
            echo '<td></td>';
            echo '<td colspan="6" class="text-muted small pb-2">';
            echo '<i class="ti ti-alert-triangle me-1 text-warning"></i>';
            echo self::h(implode(' | ', $mapped->warnings));
            echo '</td>';
            echo '</tr>';
        }
    }

    private static function showStatCard(string $icon, string $color, string $value, string $label): void
    {
        echo '<div class="col-md-4">';
        echo '<div class="card border-' . $color . '">';
        echo '<div class="card-body py-2 d-flex align-items-center gap-3">';
        echo '<i class="ti ti-' . $icon . ' text-' . $color . '" style="font-size:1.8rem"></i>';
        echo '<div><div class="fw-bold fs-4">' . self::h($value) . '</div>';
        echo '<div class="text-muted small">' . self::h($label) . '</div></div>';
        echo '</div></div></div>';
    }

    private static function h(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

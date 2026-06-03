<?php

namespace GlpiPlugin\Bridge\Page;

use GlpiPlugin\Bridge\Connection;
use GlpiPlugin\Bridge\Migration\MappedIncident;

class DryRunPage
{
    /**
     * Step 1 — resource type selector.
     * $resourceTypes comes from ConnectorInterface::getResourceTypes().
     */
    public static function showSelector(Connection $connection, array $resourceTypes, string $dryRunUrl): void
    {
        $connId = (int) $connection->fields['id'];

        echo '<div class="container-fluid p-3" style="max-width:640px">';
        echo '<div class="d-flex align-items-center justify-content-between mb-3">';
        echo '<h4 class="m-0"><i class="ti ti-list-check me-2 text-warning"></i>';
        echo self::h(__('Dry-run', 'bridge')) . '</h4>';
        echo '<a class="btn btn-outline-secondary btn-sm" href="' . self::h(Connection::getConfigURL($connId)) . '">';
        echo '<i class="ti ti-arrow-left me-1"></i>' . self::h(__('Back', 'bridge')) . '</a>';
        echo '</div>';

        echo '<div class="card">';
        echo '<div class="card-header fw-semibold">';
        echo '<i class="ti ti-plug me-1"></i>' . self::h($connection->fields['name']);
        echo '</div>';
        echo '<div class="card-body">';
        echo '<p class="text-muted mb-3">' . self::h(__('Choose the resource type to preview before migrating.', 'bridge')) . '</p>';

        foreach ($resourceTypes as $key => $meta) {
            $implemented = (bool) ($meta['implemented'] ?? false);
            $label       = (string) ($meta['label'] ?? $key);

            echo '<div class="mb-2">';
            if ($implemented) {
                echo '<form method="post" action="' . self::h($dryRunUrl) . '">';
                echo \Html::hidden('_glpi_csrf_token', ['value' => \Session::getNewCSRFToken()]);
                echo \Html::hidden('id',            ['value' => $connId]);
                echo \Html::hidden('resource_type', ['value' => $key]);
                echo '<button type="submit" class="btn btn-outline-primary w-100 text-start">';
                echo '<i class="ti ti-arrow-right me-2"></i>';
                echo self::h($label);
                echo '</button>';
                echo '</form>';
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

    /**
     * Shown when a valid but unimplemented resource type is submitted directly.
     */
    public static function showNotImplemented(Connection $connection, string $resourceType): void
    {
        echo '<div class="container-fluid p-3" style="max-width:640px">';
        echo '<div class="alert alert-info d-flex align-items-center gap-2">';
        echo '<i class="ti ti-clock" style="font-size:1.5rem"></i>';
        echo '<div>';
        echo '<strong>' . self::h($resourceType) . '</strong> — ';
        echo self::h(__('migration is not implemented yet. Check back in a future version.', 'bridge'));
        echo '</div>';
        echo '</div>';
        echo '<a class="btn btn-outline-secondary btn-sm" href="' . self::h(Connection::getConfigURL((int) $connection->fields['id'])) . '">';
        echo '<i class="ti ti-arrow-left me-1"></i>' . self::h(__('Back', 'bridge'));
        echo '</a>';
        echo '</div>';
    }

    /**
     * Step 2 — resolution results table.
     *
     * @param MappedIncident[] $results
     */
    public static function show(
        Connection $connection,
        array $results,
        int $total,
        string $resourceType = 'incidents',
        string $migrateUrl = ''
    ): void
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
        echo ' &mdash; <span class="badge bg-primary me-1">' . self::h(ucfirst($resourceType)) . '</span>';
        echo self::h(sprintf(__('sample of %d / %s total', 'bridge'), $sample, number_format($total)));
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
        $creatable = array_values(array_filter($results, static fn($r) => $r->isCreatable()));
        if ($migrateUrl !== '' && $creatable !== []) {
            echo '<form method="post" action="' . self::h($migrateUrl) . '">';
            echo \Html::hidden('_glpi_csrf_token', ['value' => \Session::getNewCSRFToken()]);
            echo \Html::hidden('id', ['value' => (int) $connection->fields['id']]);
            echo \Html::hidden('action', ['value' => 'migrate']);
            echo \Html::hidden('resource_type', ['value' => $resourceType]);
            echo \Html::hidden('migration_mode', ['value' => 'ids']);
            echo \Html::hidden('include_comments', ['value' => '1']);
            echo \Html::hidden('keep_private_comments', ['value' => '1']);
        }

        echo '<div class="card">';
        echo '<div class="card-header fw-semibold small d-flex align-items-center justify-content-between">';
        echo '<span>' . self::h(__('Resolution preview', 'bridge')) . '</span>';
        echo '<span class="text-muted fw-normal">' . self::h(__('Review matches before migrating.', 'bridge')) . '</span>';
        echo '</div>';
        echo '<div class="table-responsive">';
        echo '<table class="table table-sm table-hover align-middle mb-0" style="font-size:.82rem">';
        echo '<thead class="table-light"><tr>';
        echo '<th class="text-center">' . self::h(__('Migrate', 'bridge')) . '</th>';
        echo '<th>#</th>';
        echo '<th>' . self::h(__('Source item', 'bridge')) . '</th>';
        echo '<th>' . self::h(__('GLPI preview', 'bridge')) . '</th>';
        echo '<th>' . self::h(__('Entity', 'bridge')) . '</th>';
        echo '<th>' . self::h(__('Category', 'bridge')) . '</th>';
        echo '<th>' . self::h(__('Group / Assignee', 'bridge')) . '</th>';
        echo '<th>' . self::h(__('Requester', 'bridge')) . '</th>';
        echo '<th class="text-center">' . self::h(__('Comments', 'bridge')) . '</th>';
        echo '<th>' . self::h(__('Status', 'bridge')) . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($results as $mapped) {
            $t   = $mapped->ticket;
            $inc = $mapped->original;
            self::showRow($mapped, $t, $inc, $migrateUrl !== '');
        }

        echo '</tbody></table>';
        echo '</div>';
        echo '</div>';

        if ($migrateUrl !== '' && $creatable !== []) {
            echo '<div class="d-flex align-items-center justify-content-between mt-3">';
            echo '<div class="text-muted small">';
            echo '<i class="ti ti-lock me-1"></i>' . self::h(__('Dry-run only preview above. Migration starts only from this button.', 'bridge'));
            echo '</div>';
            echo '<button type="submit" class="btn btn-primary">';
            echo '<i class="ti ti-database-import me-1"></i>' . self::h(__('Migrate selected', 'bridge'));
            echo '</button>';
            echo '</div>';
            echo '</form>';
        }

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

    private static function showRow(MappedIncident $mapped, array $t, array $inc, bool $withSelection = false): void
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
            : (!empty($t['_requester_alt_email'])
                ? '<span class="text-warning">' . self::h($t['_requester_alt_email']) . '</span>'
                : '<span class="text-warning">—</span>');

        $sourceId = (string) ($inc['id'] ?? '');
        $sourceNumber = (string) ($inc['number'] ?? $sourceId);
        $targetStatus = self::statusLabel((int) ($t['status'] ?? 0));
        $targetDate = (string) ($t['date'] ?? '');
        $targetType = match (true) {
            array_key_exists('causecontent', $t) => 'Problem',
            array_key_exists('rolloutplancontent', $t) => 'Change',
            default => 'Ticket',
        };

        echo '<tr' . $rowClass . '>';
        echo '<td class="text-center">';
        if ($withSelection && $mapped->isCreatable() && $sourceId !== '') {
            echo '<input type="checkbox" class="form-check-input" name="source_ids[]" value="' . self::h($sourceId) . '" checked>';
        } else {
            echo '<span class="text-muted">—</span>';
        }
        echo '</td>';
        echo '<td class="text-muted">' . self::h($sourceNumber) . '</td>';
        echo '<td style="max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="' . self::h((string) ($inc['name'] ?? '')) . '">';
        echo self::h(mb_substr((string) ($inc['name'] ?? ''), 0, 60));
        echo '</td>';
        echo '<td>';
        echo '<div class="fw-semibold">' . self::h($targetType) . '</div>';
        echo '<div class="text-muted small">' . self::h($targetStatus);
        if ($targetDate !== '') {
            echo ' &middot; ' . self::h($targetDate);
        }
        echo '</div>';
        echo '<div class="text-muted small">' . self::h(__('Priority', 'bridge')) . ': ' . (int) ($t['priority'] ?? 0) . '</div>';
        echo '</td>';
        echo '<td>' . $entityText . '</td>';
        echo '<td>' . $categoryText . '</td>';
        echo '<td>' . $assigneeText . '</td>';
        echo '<td>' . $requesterText . '</td>';
        $commentCount = (int) ($inc['number_of_comments'] ?? 0);
        echo '<td class="text-center">';
        echo $commentCount > 0
            ? '<span class="badge bg-info text-dark">' . $commentCount . '</span>'
            : '<span class="text-muted">—</span>';
        echo '</td>';
        echo '<td>' . $statusBadge . '</td>';
        echo '</tr>';

        // Inline warning rows
        if ($mapped->warnings !== []) {
            echo '<tr' . $rowClass . '>';
            echo '<td></td>';
            echo '<td colspan="9" class="text-muted small pb-2">';
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

    private static function statusLabel(int $status): string
    {
        return match ($status) {
            1 => 'New',
            2 => 'Processing',
            3 => 'Planned',
            4 => 'Pending',
            5 => 'Solved',
            6 => 'Closed',
            default => $status > 0 ? 'Status ' . $status : '-',
        };
    }

    private static function h(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

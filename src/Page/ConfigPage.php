<?php

namespace GlpiPlugin\Bridge\Page;

use Dropdown;
use Entity;
use GlpiPlugin\Bridge\Connection;
use Html;
use Plugin;
use Session;

class ConfigPage
{
    public static function show(): void
    {
        $selectedId = (int) ($_GET['bridge_connection_id'] ?? 0);
        $selected = null;

        if ($selectedId > 0) {
            $connection = new Connection();
            if ($connection->getFromDB($selectedId)) {
                $selected = $connection;
            }
        }

        echo '<div class="container-fluid p-3">';
        echo '<div class="row g-3">';
        echo '<div class="col-xl-5">';
        self::showConnectionsList($selectedId);
        echo '</div>';
        echo '<div class="col-xl-7">';
        self::showConnectionForm($selected);
        echo '</div>';
        echo '</div>';
        echo '</div>';
    }

    public static function showScanResult(Connection $connection, array $result): void
    {
        if (isset($result['resources']) && is_array($result['resources'])) {
            self::showResourceScanResult($connection, $result);
            return;
        }

        $jsonText = json_encode($result['records'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $statusCode = (int) ($result['status_code'] ?? 0);
        $statusClass = ($statusCode >= 200 && $statusCode < 300) ? 'text-success' : 'text-danger';

        echo '<div class="container-fluid p-3">';

        echo '<div class="d-flex align-items-center justify-content-between mb-3">';
        echo '<div>';
        echo '<h4 class="m-0"><i class="ti ti-radar me-2 text-primary"></i>';
        echo self::h(__('SolarWinds scan', 'bridge')) . '</h4>';
        echo '<div class="text-muted small mt-1">';
        echo '<i class="ti ti-plug me-1"></i>' . self::h($connection->fields['name']);
        echo '</div>';
        echo '</div>';
        echo '<a class="btn btn-outline-secondary btn-sm" href="' . self::h(Connection::getConfigURL((int) $connection->fields['id'])) . '">';
        echo '<i class="ti ti-arrow-left me-1"></i>' . self::h(__('Back', 'bridge'));
        echo '</a>';
        echo '</div>';

        echo '<div class="card mb-3">';
        echo '<div class="card-header fw-semibold"><i class="ti ti-info-circle me-1"></i>' . self::h(__('Summary', 'bridge')) . '</div>';
        echo '<div class="card-body py-2">';
        echo '<dl class="row mb-0 small">';
        echo '<dt class="col-sm-3 text-muted">' . self::h(__('Endpoint', 'bridge')) . '</dt>';
        echo '<dd class="col-sm-9"><code class="text-break">' . self::h($result['endpoint'] ?? '') . '</code></dd>';
        echo '<dt class="col-sm-3 text-muted">' . self::h(__('HTTP status', 'bridge')) . '</dt>';
        echo '<dd class="col-sm-9"><span class="fw-semibold ' . $statusClass . '">' . self::h((string) $statusCode) . '</span></dd>';
        echo '<dt class="col-sm-3 text-muted">' . self::h(__('Records found', 'bridge')) . '</dt>';
        echo '<dd class="col-sm-9"><span class="badge bg-primary">' . (int) ($result['count'] ?? 0) . '</span></dd>';
        echo '</dl>';
        echo '</div>';
        echo '</div>';

        echo '<div class="card">';
        echo '<div class="card-header d-flex align-items-center justify-content-between">';
        echo '<span class="fw-semibold"><i class="ti ti-code me-1"></i>' . self::h(__('Raw sample', 'bridge')) . '</span>';
        echo '<button type="button" class="btn btn-sm btn-outline-secondary bridge-copy-btn"';
        echo ' data-copy-target="bridge-raw-json"';
        echo ' data-copy="' . self::h(__('Copy', 'bridge')) . '"';
        echo ' data-copied="' . self::h(__('Copied', 'bridge')) . '">';
        echo '<i class="ti ti-copy me-1"></i>' . self::h(__('Copy', 'bridge'));
        echo '</button>';
        echo '</div>';
        echo '<div class="card-body p-0">';
        echo '<p class="text-muted small px-3 pt-3 mb-2">';
        echo '<i class="ti ti-lock me-1"></i>';
        echo self::h(__('Read-only discovery data. No data was written to GLPI.', 'bridge'));
        echo '</p>';
        echo '<pre id="bridge-raw-json" class="border-top p-3 mb-0 bg-light bridge-raw-json">';
        echo self::h($jsonText);
        echo '</pre>';
        echo '</div>';
        echo '</div>';

        echo '</div>';
    }

    private static function showResourceScanResult(Connection $connection, array $result): void
    {
        $resources = $result['resources'] ?? [];
        $available = count(array_filter($resources, static fn($r) => ($r['status'] ?? '') === 'available'));
        $unavailable = count(array_filter($resources, static fn($r) => ($r['status'] ?? '') === 'unavailable'));
        $errors = count(array_filter($resources, static fn($r) => ($r['status'] ?? '') === 'error'));

        echo '<div class="container-fluid p-3">';

        echo '<div class="d-flex align-items-center justify-content-between mb-3">';
        echo '<div>';
        echo '<h4 class="m-0"><i class="ti ti-radar me-2 text-primary"></i>';
        echo self::h(__('SolarWinds discovery scan', 'bridge')) . '</h4>';
        echo '<div class="text-muted small mt-1">';
        echo '<i class="ti ti-plug me-1"></i>' . self::h($connection->fields['name']);
        echo '</div>';
        echo '</div>';
        echo '<a class="btn btn-outline-secondary btn-sm" href="' . self::h(Connection::getConfigURL((int) $connection->fields['id'])) . '">';
        echo '<i class="ti ti-arrow-left me-1"></i>' . self::h(__('Back', 'bridge'));
        echo '</a>';
        echo '</div>';

        echo '<div class="alert alert-info d-flex align-items-center gap-2">';
        echo '<i class="ti ti-lock"></i>';
        echo '<span>' . self::h(__('Read-only discovery data. No data was written to GLPI.', 'bridge')) . '</span>';
        echo '</div>';

        echo '<div class="row g-3 mb-3">';
        self::scanStatCard('circle-check', 'success', $available, __('Available', 'bridge'));
        self::scanStatCard('circle-minus', 'secondary', $unavailable, __('Unavailable', 'bridge'));
        self::scanStatCard('alert-triangle', 'danger', $errors, __('Errors', 'bridge'));
        echo '</div>';

        echo '<div class="card mb-3">';
        echo '<div class="card-header fw-semibold"><i class="ti ti-list-search me-1"></i>' . self::h(__('Resource summary', 'bridge')) . '</div>';
        echo '<div class="table-responsive">';
        echo '<table class="table table-hover align-middle mb-0">';
        echo '<thead class="table-light"><tr>';
        echo '<th>' . self::h(__('Resource', 'bridge')) . '</th>';
        echo '<th>' . self::h(__('Status', 'bridge')) . '</th>';
        echo '<th>' . self::h(__('HTTP status', 'bridge')) . '</th>';
        echo '<th>' . self::h(__('Total', 'bridge')) . '</th>';
        echo '<th>' . self::h(__('Sample', 'bridge')) . '</th>';
        echo '<th>' . self::h(__('Endpoint', 'bridge')) . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($resources as $resource) {
            $status = (string) ($resource['status'] ?? 'error');
            $badge = match ($status) {
                'available'   => 'success',
                'unavailable' => 'secondary',
                default       => 'danger',
            };
            $statusLabel = match ($status) {
                'available'   => __('Available', 'bridge'),
                'unavailable' => __('Unavailable', 'bridge'),
                default       => __('Error', 'bridge'),
            };

            echo '<tr>';
            echo '<td class="fw-semibold">' . self::h($resource['label'] ?? $resource['key'] ?? '') . '</td>';
            echo '<td><span class="badge bg-' . $badge . '">' . self::h($statusLabel) . '</span></td>';
            echo '<td>' . self::h((string) ((int) ($resource['status_code'] ?? 0) ?: '-')) . '</td>';
            echo '<td><span class="badge bg-primary">' . (int) ($resource['total'] ?? 0) . '</span></td>';
            echo '<td>' . (int) ($resource['count'] ?? 0) . '</td>';
            echo '<td><code class="small text-break">' . self::h($resource['endpoint'] ?? '') . '</code>';
            if ($status !== 'available' && !empty($resource['message'])) {
                echo '<div class="text-muted small mt-1">' . self::h($resource['message']) . '</div>';
            }
            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
        echo '</div>';
        echo '</div>';

        foreach ($resources as $resource) {
            if (($resource['status'] ?? '') !== 'available') {
                continue;
            }
            $records = $resource['records'] ?? [];
            $jsonText = json_encode($records, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $targetId = 'bridge-raw-json-' . preg_replace('/[^a-z0-9_-]/i', '-', (string) ($resource['key'] ?? 'resource'));

            echo '<div class="card mb-3">';
            echo '<div class="card-header d-flex align-items-center justify-content-between">';
            echo '<span class="fw-semibold"><i class="ti ti-code me-1"></i>' . self::h($resource['label'] ?? '') . ' — ' . self::h(__('Raw sample', 'bridge')) . '</span>';
            echo '<button type="button" class="btn btn-sm btn-outline-secondary bridge-copy-btn"';
            echo ' data-copy-target="' . self::h($targetId) . '"';
            echo ' data-copy="' . self::h(__('Copy', 'bridge')) . '"';
            echo ' data-copied="' . self::h(__('Copied', 'bridge')) . '">';
            echo '<i class="ti ti-copy me-1"></i>' . self::h(__('Copy', 'bridge'));
            echo '</button>';
            echo '</div>';
            echo '<div class="card-body p-0">';
            echo '<pre id="' . self::h($targetId) . '" class="p-3 mb-0 bg-light bridge-raw-json">';
            echo self::h($jsonText);
            echo '</pre>';
            echo '</div>';
            echo '</div>';
        }

        echo '</div>';
    }

    private static function scanStatCard(string $icon, string $color, int $value, string $label): void
    {
        echo '<div class="col-md-4">';
        echo '<div class="card h-100">';
        echo '<div class="card-body d-flex align-items-center gap-3">';
        echo '<span class="text-' . self::h($color) . '"><i class="ti ti-' . self::h($icon) . '" style="font-size:1.6rem"></i></span>';
        echo '<div>';
        echo '<div class="fs-4 fw-semibold">' . $value . '</div>';
        echo '<div class="text-muted small">' . self::h($label) . '</div>';
        echo '</div>';
        echo '</div>';
        echo '</div>';
        echo '</div>';
    }

    private static function showConnectionsList(int $selectedId): void
    {
        global $DB;

        $rows    = iterator_to_array($DB->request(['FROM' => Connection::getTable(), 'ORDER' => ['name ASC']]));
        $ajaxUrl    = self::h(Plugin::getWebDir('bridge', true) . '/ajax/test_connection.php');
        $scanUrl     = self::h(Connection::getScanURL());
        $dryRunUrl   = self::h(Plugin::getWebDir('bridge', true) . '/front/dryrun.php');
        $migrateUrl  = self::h(Plugin::getWebDir('bridge', true) . '/front/migrate.php');
        $historyUrl  = self::h(Plugin::getWebDir('bridge', true) . '/front/migration_history.php');
        $syncUsrUrl  = self::h(Plugin::getWebDir('bridge', true) . '/front/sync_users.php');
        $jobsUrl     = self::h(Plugin::getWebDir('bridge', true) . '/front/jobs.php');

        echo '<div class="card h-100">';
        echo '<div class="card-header fw-semibold">';
        echo '<i class="ti ti-plug me-1"></i>' . self::h(__('Connections', 'bridge'));
        echo '</div>';

        if (empty($rows)) {
            echo '<div class="card-body text-center py-5 text-muted">';
            echo '<i class="ti ti-plug-off" style="font-size:2.5rem;opacity:.35;"></i>';
            echo '<p class="mt-2 mb-0">' . self::h(__('No connections configured yet.', 'bridge')) . '</p>';
            echo '<p class="small">' . self::h(__('Use the form on the right to add one.', 'bridge')) . '</p>';
            echo '</div>';
        } else {
            echo '<div class="bridge-connections-table">';
            echo '<table class="table table-hover align-middle mb-0">';
            echo '<thead class="table-light"><tr>';
            echo '<th>' . self::h(__('Name', 'bridge')) . '</th>';
            echo '<th>' . self::h(__('Migration status', 'bridge')) . '</th>';
            echo '<th></th>';
            echo '</tr></thead><tbody>';

            foreach ($rows as $row) {
                $id         = (int) $row['id'];
                $isActive   = (bool) ($row['is_active'] ?? true);
                $isSelected = ($id === $selectedId);
                $classes    = array_filter(['table-active' => $isSelected, 'opacity-50' => !$isActive]);
                $rowClass   = !empty($classes) ? ' class="' . implode(' ', array_keys($classes)) . '"' : '';
                $editUrl    = Connection::getConfigURL($id);
                $host       = parse_url((string) $row['base_url'], PHP_URL_HOST) ?: $row['base_url'];
                $csrfToken  = Session::getNewCSRFToken();

                // Job summary for this connection
                $jobSummary  = \GlpiPlugin\Bridge\Migration\BridgeJob::getConnectionSummary($id);
                $lastStatus  = $jobSummary['last_status'];
                $activeJobId = $jobSummary['active_job_id'];

                echo '<tr' . $rowClass . '>';

                // Name + host
                echo '<td>';
                echo '<a class="fw-semibold text-decoration-none" href="' . self::h($editUrl) . '">';
                echo self::h($row['name']);
                echo '</a>';
                echo '<div class="text-muted small">' . self::h($host) . '</div>';
                echo '</td>';

                // Migration status column
                echo '<td>';
                if ($activeJobId !== null) {
                    $jobUrl = Plugin::getWebDir('bridge', true) . '/front/job_status.php?job_id=' . $activeJobId;
                    $isRunning = ($lastStatus === \GlpiPlugin\Bridge\Migration\BridgeJob::STATUS_RUNNING);
                    $badge = $isRunning ? 'bg-primary' : 'bg-secondary';
                    $label = $isRunning ? __('Running', 'bridge') : __('Pending', 'bridge');
                    echo '<a href="' . self::h($jobUrl) . '" class="text-decoration-none">';
                    echo '<span class="badge ' . $badge . ' me-1">' . self::h($label) . '</span>';
                    echo '<span class="text-muted small">' . self::h(__('View job', 'bridge')) . ' →</span>';
                    echo '</a>';
                } elseif ($lastStatus !== null) {
                    $badgeMap = [
                        'completed' => 'bg-success',
                        'failed'    => 'bg-danger',
                        'cancelled' => 'bg-warning text-dark',
                    ];
                    $badge = $badgeMap[$lastStatus] ?? 'bg-secondary';
                    echo '<span class="badge ' . $badge . ' me-1">' . self::h($lastStatus) . '</span>';
                    if ($jobSummary['total_created'] > 0) {
                        echo '<span class="text-muted small">' . number_format($jobSummary['total_created']) . ' ' . self::h(__('migrated', 'bridge')) . '</span>';
                    }
                    if ($jobSummary['total_failed'] > 0) {
                        echo ' <span class="text-danger small">' . $jobSummary['total_failed'] . ' ' . self::h(__('failed', 'bridge')) . '</span>';
                    }
                } else {
                    echo '<span class="text-muted small"><i class="ti ti-minus me-1"></i>' . self::h(__('No migrations yet', 'bridge')) . '</span>';
                }
                echo '</td>';

                echo '<td class="text-end text-nowrap">';

                echo '<div class="btn-group btn-group-sm" role="group" aria-label="' . self::h(__('Connection actions', 'bridge')) . '">';

                echo '<a href="' . $migrateUrl . '?id=' . $id . '"';
                echo ' class="btn btn-primary"';
                echo ' title="' . self::h(__('Migrate', 'bridge')) . '"';
                echo ' aria-label="' . self::h(__('Migrate', 'bridge')) . '">';
                echo '<i class="ti ti-database-import me-1"></i>' . self::h(__('Migrate', 'bridge'));
                echo '</a>';

                echo '<button type="button" class="btn btn-outline-secondary dropdown-toggle dropdown-toggle-split"';
                echo ' data-bs-toggle="dropdown" aria-expanded="false"';
                echo ' aria-label="' . self::h(__('More actions', 'bridge')) . '">';
                echo '<span class="visually-hidden">' . self::h(__('More actions', 'bridge')) . '</span>';
                echo '</button>';
                echo '<div class="dropdown-menu dropdown-menu-end bridge-actions-menu">';

                echo '<button type="button"';
                echo ' class="dropdown-item bridge-test-btn"';
                echo ' data-id="' . $id . '"';
                echo ' data-token="' . self::h($csrfToken) . '"';
                echo ' data-ajax="' . $ajaxUrl . '"';
                echo ' data-testing="' . self::h(__('Testing...', 'bridge')) . '"';
                echo ' data-failed="' . self::h(__('Request failed.', 'bridge')) . '"';
                echo ' data-records-label="' . self::h(__('records', 'bridge')) . '"';
                echo ' aria-label="' . self::h(__('Test connection', 'bridge')) . '">';
                echo '<i class="ti ti-plug me-2"></i>' . self::h(__('Test connection', 'bridge'));
                echo '</button>';

                echo '<a href="' . $historyUrl . '?id=' . $id . '"';
                echo ' class="dropdown-item">';
                echo '<i class="ti ti-history me-2"></i>' . self::h(__('Migration history', 'bridge'));
                echo '</a>';

                echo '<a href="' . $dryRunUrl . '?id=' . $id . '"';
                echo ' class="dropdown-item">';
                echo '<i class="ti ti-list-check me-2"></i>' . self::h(__('Dry-run', 'bridge'));
                echo '</a>';

                echo '<form method="post" action="' . $scanUrl . '">';
                echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);
                echo Html::hidden('id', ['value' => $id]);
                echo '<button type="submit" class="dropdown-item"';
                echo ' aria-label="' . self::h(__('Scan', 'bridge')) . '">';
                echo '<i class="ti ti-radar me-2"></i>' . self::h(__('Scan', 'bridge'));
                echo '</button>';
                echo '</form>';

                echo '<a href="' . $syncUsrUrl . '?id=' . $id . '"';
                echo ' class="dropdown-item">';
                echo '<i class="ti ti-users me-2"></i>' . self::h(__('Sync users', 'bridge'));
                echo '</a>';

                echo '<a href="' . $jobsUrl . '?id=' . $id . '"';
                echo ' class="dropdown-item">';
                echo '<i class="ti ti-list-details me-2"></i>' . self::h(__('Migration jobs', 'bridge'));
                echo '</a>';

                echo '<div class="dropdown-divider"></div>';

                echo '<a href="' . self::h($editUrl) . '"';
                echo ' class="dropdown-item">';
                echo '<i class="ti ti-pencil me-2"></i>' . self::h(__('Edit connection', 'bridge'));
                echo '</a>';

                $configFormUrl = Connection::getConfigFormURL();
                echo '<form method="post" action="' . self::h($configFormUrl) . '">';
                echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);
                echo Html::hidden('id', ['value' => $id]);
                echo '<button type="submit" name="purge" class="dropdown-item text-danger"';
                echo ' aria-label="' . self::h(__('Delete connection', 'bridge')) . '"';
                echo ' data-bridge-confirm="' . self::h(__('Delete this connection?', 'bridge')) . '">';
                echo '<i class="ti ti-trash me-2"></i>' . self::h(__('Delete connection', 'bridge'));
                echo '</button>';
                echo '</form>';

                echo '</div>';
                echo '</div>';
                echo '</td>';
                echo '</tr>';
            }

            echo '</tbody></table>';
            echo '</div>';
        }

        echo '</div>';

    }

    private static function showConnectionForm(?Connection $connection): void
    {
        $fields = $connection?->fields ?? [
            'id'                 => 0,
            'name'               => '',
            'system_type'        => Connection::TYPE_SOLARWINDS,
            'base_url'           => '',
            'auth_type'          => Connection::AUTH_BEARER,
            'auth_user'          => '',
            'custom_header_name' => '',
            'entities_id'        => $_SESSION['glpiactive_entity'] ?? 0,
            'default_groups_id'  => 0,
            'is_active'          => 1,
            'comment'            => '',
            'auth_secret'        => '',
        ];

        $isEdit = (int) ($fields['id'] ?? 0) > 0;

        echo '<div class="card">';
        echo '<div class="card-header fw-semibold">';
        echo '<i class="ti ti-' . ($isEdit ? 'edit' : 'plus') . ' me-1"></i>';
        echo self::h($isEdit ? __('Edit connection', 'bridge') : __('New connection', 'bridge'));
        echo '</div>';
        echo '<div class="card-body">';
        echo '<form method="post" action="' . self::h(Connection::getConfigFormURL()) . '">';
        echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);

        if ($isEdit) {
            echo Html::hidden('id', ['value' => (int) $fields['id']]);
        }

        echo '<div class="row g-3">';

        echo '<div class="col-md-6">';
        self::showInput('name', __('Name', 'bridge'), (string) $fields['name'], true, __('e.g. SolarWinds Production', 'bridge'));
        echo '</div>';
        echo '<div class="col-md-6">';
        self::showSelect('system_type', __('Source system', 'bridge'), Connection::getSupportedSystems(), (string) $fields['system_type']);
        echo '</div>';

        echo '<div class="col-12">';
        self::showInput('base_url', __('Base URL', 'bridge'), (string) $fields['base_url'], true, 'https://your-instance.example.com');
        echo '</div>';

        echo '<div class="col-md-6">';
        self::showSelect('auth_type', __('Authentication', 'bridge'), Connection::getAuthTypes(), (string) $fields['auth_type']);
        echo '</div>';
        echo '<div class="col-md-6">';
        self::showInput('auth_user', __('User', 'bridge'), (string) ($fields['auth_user'] ?? ''), false, __('Required for Basic auth', 'bridge'));
        echo '</div>';

        echo '<div class="col-md-6">';
        $secretLabel = $isEdit && !empty($fields['auth_secret'])
            ? __('Secret / token (leave blank to keep current)', 'bridge')
            : __('Secret or token', 'bridge');
        self::showPassword('auth_secret_plain', $secretLabel);
        echo '</div>';
        echo '<div class="col-md-6">';
        self::showInput('custom_header_name', __('Custom header name', 'bridge'), (string) ($fields['custom_header_name'] ?? ''), false, __('Required for Custom header auth', 'bridge'));
        echo '</div>';

        echo '<div class="col-md-6">';
        echo '<label class="form-label">' . self::h(__('Fallback entity', 'bridge')) . '</label>';
        echo '<div class="form-text text-muted mb-1" style="font-size:.78rem">' . self::h(__('Used when a site cannot be matched by name.', 'bridge')) . '</div>';
        Entity::dropdown([
            'name'   => 'entities_id',
            'value'  => (int) ($fields['entities_id'] ?? 0),
            'entity' => $_SESSION['glpiactiveentities'] ?? -1,
        ]);
        echo '</div>';

        echo '<div class="col-md-6">';
        echo '<label class="form-label">' . self::h(__('Fallback group', 'bridge')) . '</label>';
        echo '<div class="form-text text-muted mb-1" style="font-size:.78rem">' . self::h(__('Used when an assignee group cannot be matched by name.', 'bridge')) . '</div>';
        \Group::dropdown([
            'name'  => 'default_groups_id',
            'value' => (int) ($fields['default_groups_id'] ?? 0),
        ]);
        echo '</div>';

        echo '<div class="col-md-6 d-flex align-items-end pb-1">';
        $checked = (int) ($fields['is_active'] ?? 1) ? ' checked' : '';
        echo '<div class="form-check form-switch">';
        echo '<input class="form-check-input" type="checkbox" role="switch" id="bridge-is_active" name="is_active" value="1"' . $checked . '>';
        echo '<label class="form-check-label" for="bridge-is_active">' . self::h(__('Active', 'bridge')) . '</label>';
        echo '</div>';
        echo '</div>';

        echo '<div class="col-12">';
        echo '<label class="form-label" for="bridge-comment">' . self::h(__('Notes', 'bridge')) . '</label>';
        echo '<textarea class="form-control" id="bridge-comment" name="comment" rows="2">';
        echo self::h((string) ($fields['comment'] ?? ''));
        echo '</textarea>';
        echo '</div>';

        echo '</div>';

        echo '<div class="d-flex gap-2 mt-4">';
        echo '<button type="submit" name="' . ($isEdit ? 'update' : 'add') . '" class="btn btn-primary">';
        echo '<i class="ti ti-device-floppy me-1"></i>' . self::h(__('Save', 'bridge'));
        echo '</button>';

        if ($isEdit) {
            echo '<button type="submit" name="purge" class="btn btn-outline-danger ms-auto"';
            echo ' data-bridge-confirm="' . self::h(__('Delete this connection?', 'bridge')) . '">';
            echo '<i class="ti ti-trash me-1"></i>' . self::h(__('Delete', 'bridge'));
            echo '</button>';
        }

        echo '</div>';
        echo '</form>';
        echo '</div>';
        echo '</div>';
    }

    private static function showInput(string $name, string $label, string $value, bool $required, string $placeholder = ''): void
    {
        $ph = $placeholder !== '' ? ' placeholder="' . self::h($placeholder) . '"' : '';
        echo '<label class="form-label" for="bridge-' . self::h($name) . '">' . self::h($label);
        if ($required) {
            echo ' <span class="text-danger" aria-hidden="true">*</span>';
        }
        echo '</label>';
        echo '<input class="form-control" id="bridge-' . self::h($name) . '" name="' . self::h($name) . '"';
        echo ' value="' . self::h($value) . '"' . ($required ? ' required' : '') . $ph . '>';
    }

    private static function showPassword(string $name, string $label): void
    {
        echo '<label class="form-label" for="bridge-' . self::h($name) . '">' . self::h($label) . '</label>';
        echo '<input class="form-control" id="bridge-' . self::h($name) . '" name="' . self::h($name) . '" type="password" autocomplete="new-password">';
    }

    private static function showSelect(string $name, string $label, array $options, string $value): void
    {
        echo '<label class="form-label" for="bridge-' . self::h($name) . '">' . self::h($label) . '</label>';
        Dropdown::showFromArray($name, $options, [
            'value' => $value,
            'width' => '100%',
        ]);
    }

    private static function h(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

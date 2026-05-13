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
        echo '<button type="button" class="btn btn-sm btn-outline-secondary" id="bridge-copy-btn"';
        echo ' onclick="navigator.clipboard.writeText(document.getElementById(\'bridge-raw-json\').textContent)';
        echo '.then(()=>{ this.innerHTML=\'<i class=\\\'ti ti-check me-1\\\'></i>' . addslashes(self::h(__('Copied', 'bridge'))) . '\'; setTimeout(()=>{ this.innerHTML=\'<i class=\\\'ti ti-copy me-1\\\'></i>' . addslashes(self::h(__('Copy', 'bridge'))) . '\'; },2000); })">';
        echo '<i class="ti ti-copy me-1"></i>' . self::h(__('Copy', 'bridge'));
        echo '</button>';
        echo '</div>';
        echo '<div class="card-body p-0">';
        echo '<p class="text-muted small px-3 pt-3 mb-2">';
        echo '<i class="ti ti-lock me-1"></i>';
        echo self::h(__('Read-only discovery data. Migration is not implemented yet.', 'bridge'));
        echo '</p>';
        echo '<pre id="bridge-raw-json" class="border-top p-3 mb-0 bg-light" style="max-height:620px;overflow:auto;font-size:.8rem;">';
        echo self::h($jsonText);
        echo '</pre>';
        echo '</div>';
        echo '</div>';

        echo '</div>';
    }

    private static function showConnectionsList(int $selectedId): void
    {
        global $DB;

        $rows    = iterator_to_array($DB->request(['FROM' => Connection::getTable(), 'ORDER' => ['name ASC']]));
        $ajaxUrl   = self::h(Plugin::getWebDir('bridge', true) . '/ajax/test_connection.php');
        $scanUrl   = self::h(Connection::getScanURL());
        $dryRunUrl = self::h(Plugin::getWebDir('bridge', true) . '/front/dryrun.php');

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
            echo '<div class="table-responsive">';
            echo '<table class="table table-hover align-middle mb-0">';
            echo '<thead class="table-light"><tr>';
            echo '<th>' . self::h(__('Name', 'bridge')) . '</th>';
            echo '<th class="text-center">' . self::h(__('Active', 'bridge')) . '</th>';
            echo '<th></th>';
            echo '</tr></thead><tbody>';

            foreach ($rows as $row) {
                $id         = (int) $row['id'];
                $isSelected = ($id === $selectedId);
                $rowClass   = $isSelected ? ' class="table-active"' : '';
                $editUrl    = Connection::getConfigURL($id);
                $host       = parse_url((string) $row['base_url'], PHP_URL_HOST) ?: $row['base_url'];
                $csrfToken  = Session::getNewCSRFToken();

                echo '<tr' . $rowClass . '>';

                // Name + host
                echo '<td>';
                echo '<a class="fw-semibold text-decoration-none" href="' . self::h($editUrl) . '">';
                echo self::h($row['name']);
                echo '</a>';
                echo '<div class="text-muted small">' . self::h($host) . '</div>';
                // Test result appears here, under the name
                echo '<div id="bridge-test-result-' . $id . '" class="small mt-1"></div>';
                echo '</td>';

                // Active
                echo '<td class="text-center">';
                echo (int) $row['is_active']
                    ? '<span class="badge bg-success">&#10003;</span>'
                    : '<span class="badge bg-secondary">&#8212;</span>';
                echo '</td>';

                // Actions
                echo '<td class="text-end text-nowrap">';

                // Test button
                echo '<button type="button"';
                echo ' class="btn btn-sm btn-outline-secondary bridge-test-btn me-1"';
                echo ' data-id="' . $id . '"';
                echo ' data-token="' . self::h($csrfToken) . '"';
                echo ' data-ajax="' . $ajaxUrl . '"';
                echo ' title="' . self::h(__('Test connection', 'bridge')) . '">';
                echo '<i class="ti ti-plug"></i>';
                echo '</button>';

                // Dry-run button — GET to selector page (read-only, no CSRF needed)
                echo '<a href="' . $dryRunUrl . '?id=' . $id . '"';
                echo ' class="btn btn-sm btn-outline-warning me-1"';
                echo ' title="' . self::h(__('Dry-run', 'bridge')) . '">';
                echo '<i class="ti ti-list-check"></i>';
                echo '</a>';

                // Scan button (form POST)
                echo '<form method="post" action="' . $scanUrl . '" class="d-inline">';
                echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);
                echo Html::hidden('id', ['value' => $id]);
                echo '<button type="submit" class="btn btn-sm btn-outline-primary"';
                echo ' title="' . self::h(__('Scan incidents', 'bridge')) . '">';
                echo '<i class="ti ti-radar"></i>';
                echo '</button>';
                echo '</form>';

                echo '</td>';
                echo '</tr>';
            }

            echo '</tbody></table>';
            echo '</div>';
        }

        echo '</div>';

        // One shared JS block for all test buttons on this page
        $lblTesting = self::h(__('Testing…', 'bridge'));
        echo <<<JS
        <script>
        document.querySelectorAll('.bridge-test-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var id      = btn.dataset.id;
                var token   = btn.dataset.token;
                var ajaxUrl = btn.dataset.ajax;
                var result  = document.getElementById('bridge-test-result-' + id);

                btn.disabled = true;
                btn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status"></span>';
                if (result) result.innerHTML = '<span class="text-muted">{$lblTesting}</span>';

                fetch(ajaxUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-Glpi-Csrf-Token': token
                    },
                    body: '_glpi_csrf_token=' + encodeURIComponent(token) + '&id=' + encodeURIComponent(id)
                })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (!result) return;
                    if (data.ok) {
                        result.innerHTML =
                            '<span class="text-success">'
                            + '<i class="ti ti-circle-check me-1"></i>'
                            + data.latency_ms + 'ms &mdash; '
                            + data.total.toLocaleString() + ' incidents'
                            + '</span>';
                    } else {
                        result.innerHTML =
                            '<span class="text-danger">'
                            + '<i class="ti ti-circle-x me-1"></i>'
                            + data.message
                            + (data.status ? ' (HTTP ' + data.status + ')' : '')
                            + '</span>';
                    }
                })
                .catch(function () {
                    if (result) result.innerHTML = '<span class="text-danger"><i class="ti ti-circle-x me-1"></i>Request failed.</span>';
                })
                .finally(function () {
                    btn.disabled = false;
                    btn.innerHTML = '<i class="ti ti-plug"></i>';
                });
            });
        });
        </script>
        JS;
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
            echo ' onclick="return confirm(\'' . addslashes(self::h(__('Delete this connection?', 'bridge'))) . '\')">';
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

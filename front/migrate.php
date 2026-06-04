<?php

use GlpiPlugin\Bridge\Connection;
use GlpiPlugin\Bridge\Connector\ConnectorFactory;
use GlpiPlugin\Bridge\Migration\BridgeJob;
use GlpiPlugin\Bridge\Migration\MigrationCursor;
use GlpiPlugin\Bridge\Migration\MigrationEngine;
use GlpiPlugin\Bridge\Page\MigratePage;
use GlpiPlugin\Bridge\Resolver\GlpiResolver;

Session::checkRight('config', UPDATE);

$normalizeDate = static function (string $date): string {
    $date = trim($date);
    if ($date === '') {
        return '';
    }
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    return $parsed instanceof DateTimeImmutable ? $parsed->format('Y-m-d') : '';
};

$id         = (int) ($_REQUEST['id'] ?? 0);
$connection = new Connection();

if (!$id || !$connection->getFromDB($id)) {
    Session::addMessageAfterRedirect(__('Connection not found.', 'bridge'), false, ERROR);
    Html::redirect(Connection::getConfigURL());
}

if (!(int) ($connection->fields['is_active'] ?? 1)) {
    Session::addMessageAfterRedirect(__('This connection is inactive.', 'bridge'), false, WARNING);
    Html::redirect(Connection::getConfigURL($id));
}

// Build URLs relative to the current script so they work in both
// /plugins/bridge/front/ and /marketplace/bridge/front/ contexts.
$_frontDir   = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/');
$migrateUrl  = $_frontDir . '/migrate.php';
$historyUrl  = $_frontDir . '/migration_history.php';

Html::header(__('Migration', 'bridge'), '', 'config', 'plugins');

try {
    $client        = ConnectorFactory::make($connection);
    $resourceTypes = $client->getResourceTypes();
    $action        = (string) ($_POST['action'] ?? '');
    $resourceType  = (string) ($_REQUEST['resource_type'] ?? '');

    // GET or no action → first choose resource, then show the focused form.
    if ($action === '' || $_SERVER['REQUEST_METHOD'] !== 'POST') {
        if ($resourceType === '' || !isset($resourceTypes[$resourceType])) {
            MigratePage::showSelector($connection, $resourceTypes, $migrateUrl, $historyUrl);
        } elseif (!($resourceTypes[$resourceType]['implemented'] ?? false)) {
            Session::addMessageAfterRedirect(__('That resource type is not implemented yet.', 'bridge'), false, WARNING);
            MigratePage::showSelector($connection, $resourceTypes, $migrateUrl, $historyUrl);
        } else {
            MigratePage::showForm($connection, $resourceTypes, $migrateUrl, $historyUrl, $resourceType);
        }
        Html::footer();
        exit;
    }

    $resourceType = $resourceType !== '' ? $resourceType : 'incidents';

    // Validate resource type is implemented
    if (!($resourceTypes[$resourceType]['implemented'] ?? false)) {
        Session::addMessageAfterRedirect(__('That resource type is not implemented yet.', 'bridge'), false, WARNING);
        MigratePage::showSelector($connection, $resourceTypes, $migrateUrl, $historyUrl);
        Html::footer();
        exit;
    }

    $normalizer = ConnectorFactory::makeNormalizer((string) $connection->fields['system_type']);
    $resolver   = GlpiResolver::create();

    $engine = new MigrationEngine(
        $client,
        $normalizer,
        $resolver,
        $id,
        (int) $connection->fields['entities_id'],
        (int) ($connection->fields['default_groups_id'] ?? 0),
        (int) ($_POST['default_requesters_id'] ?? 0),
        $resourceType,
    );

    $migrationMode = (string) ($_POST['migration_mode'] ?? 'filters');
    $timePeriod    = (string) ($_POST['time_period'] ?? 'recent');

    $sourceIds = $migrationMode === 'ids' ? ($_POST['source_ids'] ?? '') : '';
    if (is_array($sourceIds)) {
        $sourceIds = implode(',', array_map(static fn($id) => (string) $id, $sourceIds));
    }

    $options = [
        'limit'               => max(1, min(500, (int) ($_POST['limit'] ?? 50))),
        'start_page'          => $timePeriod === 'manual' ? max(1, (int) ($_POST['start_page'] ?? 1)) : 1,
        'source_ids'          => (string) $sourceIds,
        'state'               => $migrationMode === 'filters' ? (string) ($_POST['state'] ?? '') : '',
        'created_after'       => $migrationMode === 'filters' && $timePeriod === 'from_date'
            ? $normalizeDate((string) ($_POST['created_after'] ?? ''))
            : '',
        'updated_after'       => $migrationMode === 'filters' && $timePeriod === 'incremental'
            ? $normalizeDate((string) ($_POST['updated_after'] ?? ''))
            : '',
        'include_comments'      => isset($_POST['include_comments']),
        'include_attachments'   => isset($_POST['include_attachments']),
        'keep_private_comments' => isset($_POST['keep_private_comments']),
        'dry_run'             => $action === 'dryrun',
    ];

    $isDryRun = $action === 'dryrun';

    // ── P4: validate options ──────────────────────────────────────────────
    if (!$isDryRun) {
        $validationErrors = BridgeJob::validateOptions($options);
        if (!empty($validationErrors)) {
            foreach ($validationErrors as $err) {
                Session::addMessageAfterRedirect(htmlspecialchars($err, ENT_QUOTES, 'UTF-8'), false, ERROR);
            }
            MigratePage::showForm($connection, $resourceTypes, $migrateUrl, $historyUrl, $resourceType);
            Html::footer();
            exit;
        }

        // ── P4: block concurrent jobs ─────────────────────────────────────
        $activeJob = BridgeJob::findActive($id, $resourceType);
        if ($activeJob !== null) {
            $jobUrl = $_frontDir . '/job_status.php?job_id=' . $activeJob->id();
            Session::addMessageAfterRedirect(
                sprintf(
                    __('A migration job for this connection is already %s. View it or wait for it to finish.', 'bridge'),
                    $activeJob->status()
                ),
                false,
                WARNING
            );
            Html::redirect($jobUrl);
            exit;
        }
    }

    if ($isDryRun) {
        // Dry-run: execute inline and show preview immediately
        $cursor = null;
        if (!empty($options['created_after'])) {
            $optionsHash = MigrationCursor::hashOptions($options);
            $cursor      = MigrationCursor::findActive($id, $resourceType, $optionsHash);
        }
        if (isset($_POST['reset_cursor']) && $cursor !== null) {
            $cursor->cancel();
            $cursor = null;
        }
        [$result, $cursor] = $engine->run($options, $cursor);
        MigratePage::showResult($connection, $result, $resourceType, $historyUrl, $cursor);
    } else {
        // Real migration: create a background job, then show a success page
        // with a direct link. Using Html::redirect() after Html::header() can
        // fail in some GLPI versions / contexts; an inline page is always safe.
        $job    = BridgeJob::create($id, $resourceType, $options, (int) ($_SESSION['glpiID'] ?? 0));
        // Build URL relative to the current script path so it works in both
        // /plugins/bridge/front/ and /marketplace/bridge/front/ contexts.
        $jobUrl = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/') . '/job_status.php?job_id=' . $job->id();

        $h = static fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        echo '<div class="container-fluid py-4 px-4" style="max-width:560px">';
        echo '<div class="alert alert-success d-flex align-items-center gap-3 mb-4">';
        echo '<i class="ti ti-circle-check fs-3"></i>';
        echo '<div>';
        echo '<div class="fw-semibold">' . $h(sprintf(__('Migration job #%d created successfully.', 'bridge'), $job->id())) . '</div>';
        echo '<div class="small">' . $h(__('The job will start within 60 seconds via the GLPI scheduler.', 'bridge')) . '</div>';
        echo '</div>';
        echo '</div>';
        echo '<div class="d-flex gap-2">';
        echo '<a class="btn btn-primary" href="' . $h($jobUrl) . '">';
        echo '<i class="ti ti-eye me-1"></i>' . $h(__('View job progress', 'bridge'));
        echo '</a>';
        echo '<a class="btn btn-outline-secondary" href="' . $h(Connection::getConfigURL($id)) . '">';
        echo '<i class="ti ti-arrow-left me-1"></i>' . $h(__('Back', 'bridge'));
        echo '</a>';
        echo '</div>';
        echo '<script>setTimeout(function(){ window.location=' . json_encode($jobUrl) . '; }, 1500);</script>';
        echo '</div>';
    }
} catch (Throwable $e) {
    // Log to PHP error log (Toolbox::logError removed in GLPI 11)
    error_log('Bridge plugin error in migrate.php: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());

    $msg  = $e->getMessage();
    $hint = '';

    // Detect missing tables (plugin not reinstalled after update)
    if (str_contains($msg, "doesn't exist") || str_contains($msg, 'Table') || str_contains($msg, 'glpi_plugin_bridge')) {
        $hint = __("A required plugin table is missing. Please go to Setup → Plugins → Bridge → Uninstall → Install to recreate the tables, then try again.", 'bridge');
    }

    echo '<div class="container-fluid p-4" style="max-width:700px">';
    echo '<div class="alert alert-danger">';
    echo '<h5 class="alert-heading"><i class="ti ti-alert-circle me-1"></i>' . htmlspecialchars(__('Migration error', 'bridge'), ENT_QUOTES, 'UTF-8') . '</h5>';
    if ($msg !== '') {
        echo '<p class="mb-0"><code>' . htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') . '</code></p>';
    }
    if ($hint !== '') {
        echo '<hr><p class="mb-0"><i class="ti ti-info-circle me-1"></i>' . htmlspecialchars($hint, ENT_QUOTES, 'UTF-8') . '</p>';
    }
    echo '</div>';
    echo '<a class="btn btn-secondary" href="' . htmlspecialchars(Connection::getConfigURL($id), ENT_QUOTES, 'UTF-8') . '">';
    echo '<i class="ti ti-arrow-left me-1"></i>' . htmlspecialchars(__('Back', 'bridge'), ENT_QUOTES, 'UTF-8');
    echo '</a>';
    echo '</div>';
}

Html::footer();

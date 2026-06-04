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

$migrateUrl = Plugin::getWebDir('bridge', true) . '/front/migrate.php';
$historyUrl = Plugin::getWebDir('bridge', true) . '/front/migration_history.php';

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
            $jobUrl = Plugin::getWebDir('bridge', true) . '/front/job_status.php?job_id=' . $activeJob->id();
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
        // Real migration: create a background job and redirect to status page
        $job        = BridgeJob::create($id, $resourceType, $options, (int) ($_SESSION['glpiID'] ?? 0));
        $jobUrl     = Plugin::getWebDir('bridge', true) . '/front/job_status.php?job_id=' . $job->id();
        Session::addMessageAfterRedirect(
            sprintf(__('Migration job #%d created. It will start within 60 seconds.', 'bridge'), $job->id()),
            true,
            INFO
        );
        Html::redirect($jobUrl);
        exit;
    }
} catch (Throwable $e) {
    // Log to GLPI so it appears in the error log
    \Toolbox::logError('Bridge plugin error in migrate.php: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());

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

<?php

use GlpiPlugin\Bridge\Connection;
use GlpiPlugin\Bridge\Connector\ConnectorFactory;
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

    $result = $engine->run($options);

    MigratePage::showResult($connection, $result, $resourceType, $historyUrl);
} catch (Throwable $e) {
    echo '<div class="alert alert-danger m-3">';
    echo htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
    echo '</div>';
    echo '<div class="m-3"><a class="btn btn-secondary" href="' . htmlspecialchars(Connection::getConfigURL($id), ENT_QUOTES, 'UTF-8') . '">';
    echo __('Back', 'bridge') . '</a></div>';
}

Html::footer();

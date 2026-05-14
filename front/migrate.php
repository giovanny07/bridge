<?php

use GlpiPlugin\Bridge\Connection;
use GlpiPlugin\Bridge\Connector\ConnectorFactory;
use GlpiPlugin\Bridge\Migration\MigrationEngine;
use GlpiPlugin\Bridge\Page\MigratePage;
use GlpiPlugin\Bridge\Resolver\GlpiResolver;

Session::checkRight('config', UPDATE);

$id         = (int) ($_REQUEST['id'] ?? 0);
$connection = new Connection();

if (!$id || !$connection->getFromDB($id)) {
    Session::addMessageAfterRedirect(__('Connection not found.', 'bridge'), false, ERROR);
    Html::redirect(Connection::getConfigURL());
}

$migrateUrl = Plugin::getWebDir('bridge', true) . '/front/migrate.php';
$historyUrl = Plugin::getWebDir('bridge', true) . '/front/migration_history.php';

Html::header(__('Migration', 'bridge'), '', 'config', 'plugins');

try {
    $client        = ConnectorFactory::make($connection);
    $resourceTypes = $client->getResourceTypes();
    $action        = (string) ($_POST['action'] ?? '');

    // GET or no action → show form
    if ($action === '' || $_SERVER['REQUEST_METHOD'] !== 'POST') {
        MigratePage::showForm($connection, $resourceTypes, $migrateUrl, $historyUrl);
        Html::footer();
        exit;
    }

    $resourceType = (string) ($_POST['resource_type'] ?? 'incidents');

    // Validate resource type is implemented
    if (!($resourceTypes[$resourceType]['implemented'] ?? false)) {
        Session::addMessageAfterRedirect(__('That resource type is not implemented yet.', 'bridge'), false, WARNING);
        MigratePage::showForm($connection, $resourceTypes, $migrateUrl, $historyUrl);
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
    );

    $options = [
        'limit'               => max(1, min(500, (int) ($_POST['limit'] ?? 50))),
        'start_page'          => max(1, (int) ($_POST['start_page'] ?? 1)),
        'source_ids'          => (string) ($_POST['source_ids'] ?? ''),
        'state'               => (string) ($_POST['state'] ?? ''),
        'created_after'       => (string) ($_POST['created_after'] ?? ''),
        'updated_after'       => (string) ($_POST['updated_after'] ?? ''),
        'include_comments'    => isset($_POST['include_comments']),
        'include_attachments' => isset($_POST['include_attachments']),
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

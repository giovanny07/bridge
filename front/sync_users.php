<?php

use GlpiPlugin\Bridge\Connection;
use GlpiPlugin\Bridge\Connector\ConnectorFactory;
use GlpiPlugin\Bridge\Migration\UserSyncer;
use GlpiPlugin\Bridge\Page\SyncUsersPage;
use GlpiPlugin\Bridge\Profile;
use GlpiPlugin\Bridge\Resolver\GlpiResolver;

$requestedAction = (string) ($_POST['action'] ?? '');
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $requestedAction === 'sync') {
    Profile::checkMigrate(UPDATE);
} else {
    Profile::checkMigrate(READ);
}

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

$syncUrl = Connection::getPluginBaseURL() . '/front/sync_users.php';

Html::header(__('User sync', 'bridge'), '', 'config', 'plugins');

try {
    $action = $requestedAction;

    if ($action === '' || $_SERVER['REQUEST_METHOD'] !== 'POST') {
        SyncUsersPage::showForm($connection, $syncUrl);
        Html::footer();
        exit;
    }

    $connector = ConnectorFactory::make($connection);
    $resolver  = GlpiResolver::create();
    $syncer    = new UserSyncer(
        $connector,
        $resolver,
        (int) ($connection->fields['entities_id'] ?? 0),
    );

    $options = [
        'limit'           => max(1, min(2000, (int) ($_POST['limit']      ?? 200))),
        'start_page'      => max(1, (int) ($_POST['start_page']           ?? 1)),
        'source_ids'      => (string) ($_POST['source_ids']               ?? ''),
        'role_filter'     => trim((string) ($_POST['role_filter']         ?? '')),
        'skip_disabled'   => isset($_POST['skip_disabled']),
        'update_existing' => isset($_POST['update_existing']),
        'dry_run'         => $action === 'dryrun',
    ];

    $result = $syncer->run($options);
    SyncUsersPage::showResult($connection, $result, $syncUrl);

} catch (Throwable $e) {
    echo '<div class="alert alert-danger m-3">' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</div>';
    echo '<div class="m-3"><a class="btn btn-secondary" href="' . htmlspecialchars(Connection::getConfigURL($id), ENT_QUOTES, 'UTF-8') . '">' . __('Back', 'bridge') . '</a></div>';
}

Html::footer();

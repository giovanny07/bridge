<?php

use GlpiPlugin\Bridge\Connection;
use GlpiPlugin\Bridge\Migration\MigrationRecord;
use GlpiPlugin\Bridge\Page\HistoryPage;

Session::checkRight('config', UPDATE);

$id         = (int) ($_REQUEST['id'] ?? 0);
$connection = new Connection();

if (!$id || !$connection->getFromDB($id)) {
    Session::addMessageAfterRedirect(__('Connection not found.', 'bridge'), false, ERROR);
    Html::redirect(Connection::getConfigURL());
}

$_frontDir  = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/');
$migrateUrl = $_frontDir . '/migrate.php';
$purgeUrl   = $_frontDir . '/migration_history.php';

// Handle purge actions (POST only)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    $purged = match ($action) {
        'purgeFailed'   => MigrationRecord::purgeFailed($id),
        'purgeAll'      => MigrationRecord::purgeAll($id),
        'purgeSelected' => MigrationRecord::purgeByIds($id, (array) ($_POST['ids'] ?? [])),
        default         => 0,
    };

    if ($purged > 0) {
        Session::addMessageAfterRedirect(
            sprintf(__('%d record(s) purged.', 'bridge'), $purged),
            true,
            INFO
        );
    }

    Html::redirect($purgeUrl . '?id=' . $id);
}

Html::header(__('Migration history', 'bridge'), '', 'config', 'plugins');

try {
    HistoryPage::show($connection, $migrateUrl, $purgeUrl);
} catch (Throwable $e) {
    echo '<div class="alert alert-danger m-3">' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</div>';
}

Html::footer();

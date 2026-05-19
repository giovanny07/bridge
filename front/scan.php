<?php

use GlpiPlugin\Bridge\Connection;
use GlpiPlugin\Bridge\Connector\ConnectorFactory;
use GlpiPlugin\Bridge\Page\ConfigPage;

Session::checkRight('config', UPDATE);

$id = (int) ($_POST['id'] ?? 0);
$connection = new Connection();

if (!$id || !$connection->getFromDB($id)) {
    Session::addMessageAfterRedirect(__('Connection not found.', 'bridge'), false, ERROR);
    Html::redirect(Connection::getConfigURL());
}

if (!(int) ($connection->fields['is_active'] ?? 1)) {
    Session::addMessageAfterRedirect(__('This connection is inactive.', 'bridge'), false, WARNING);
    Html::redirect(Connection::getConfigURL($id));
}

Html::header(__('Bridge scan', 'bridge'), '', 'config', 'plugins');

try {
    $client = ConnectorFactory::make($connection);
    $result = $client->scanIncidents(10);
    ConfigPage::showScanResult($connection, $result);
} catch (Throwable $e) {
    echo '<div class="alert alert-danger m-3">';
    echo htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
    echo '</div>';
    echo '<div class="m-3">';
    echo '<a class="btn btn-secondary" href="' . htmlspecialchars(Connection::getConfigURL($id), ENT_QUOTES, 'UTF-8') . '">';
    echo __('Back', 'bridge');
    echo '</a>';
    echo '</div>';
}

Html::footer();

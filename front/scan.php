<?php

use GlpiPlugin\Bridge\Connection;
use GlpiPlugin\Bridge\Connector\SolarWindsClient;
use GlpiPlugin\Bridge\Page\ConfigPage;

Session::checkRight('config', UPDATE);

$id = (int) ($_POST['id'] ?? 0);
$connection = new Connection();

if (!$id || !$connection->getFromDB($id)) {
    Session::addMessageAfterRedirect(__('Connection not found.', 'bridge'), false, ERROR);
    Html::redirect(Connection::getConfigURL());
}

Html::header(__('Bridge scan', 'bridge'), '', 'config', 'plugins');

try {
    $client = SolarWindsClient::fromConnection($connection);
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

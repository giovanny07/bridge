<?php

use GlpiPlugin\Bridge\Connection;
use GlpiPlugin\Bridge\Connector\SolarWindsClient;

Session::checkRight('config', UPDATE);

header('Content-Type: application/json; charset=UTF-8');

$id         = (int) ($_POST['id'] ?? 0);
$connection = new Connection();

if (!$id || !$connection->getFromDB($id)) {
    echo json_encode(['ok' => false, 'message' => __('Connection not found.', 'bridge')]);
    exit;
}

if (($connection->fields['system_type'] ?? '') !== Connection::TYPE_SOLARWINDS) {
    echo json_encode(['ok' => false, 'message' => __('Unsupported source system.', 'bridge')]);
    exit;
}

$client = SolarWindsClient::fromConnection($connection);
$result = $client->testConnection();

echo json_encode($result);

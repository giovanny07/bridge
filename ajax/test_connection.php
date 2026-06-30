<?php

use GlpiPlugin\Bridge\Connection;
use GlpiPlugin\Bridge\Connector\ConnectorFactory;

Session::checkRight('config', UPDATE);
Session::checkCSRF($_POST, true);

header('Content-Type: application/json; charset=UTF-8');

$id         = (int) ($_POST['id'] ?? 0);
$connection = new Connection();

try {
    if (!$id || !$connection->getFromDB($id)) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'message' => __('Connection not found.', 'bridge')]);
        exit;
    }

    if (($connection->fields['system_type'] ?? '') !== Connection::TYPE_SOLARWINDS) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'message' => __('Unsupported source system.', 'bridge')]);
        exit;
    }

    $client = ConnectorFactory::make($connection);
    $result = $client->testConnection();

    echo json_encode($result);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok'      => false,
        'message' => $e->getMessage(),
    ]);
}

<?php

use GlpiPlugin\Bridge\Migration\BridgeJob;

Session::checkRight('config', READ);
header('Content-Type: application/json');

$jobId = (int) ($_GET['job_id'] ?? 0);
if ($jobId <= 0) {
    echo json_encode(['error' => 'Missing job_id']);
    exit;
}

// Allow cancellation via POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel'])) {
    Session::checkRight('config', UPDATE);
    $job = BridgeJob::getById($jobId);
    if ($job && !$job->isFinished()) {
        $job->cancel();
    }
}

echo json_encode(BridgeJob::getStatusPayload($jobId));

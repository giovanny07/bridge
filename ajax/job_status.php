<?php

use GlpiPlugin\Bridge\Migration\BridgeJob;

Session::checkRight('config', READ);
header('Content-Type: application/json');

$jobId = (int) ($_GET['job_id'] ?? 0);
if ($jobId <= 0) {
    echo json_encode(['error' => 'Missing job_id']);
    exit;
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Session::checkRight('config', UPDATE);
    $job = BridgeJob::getById($jobId);
    if ($job) {
        if (isset($_POST['cancel']) && !$job->isFinished()) {
            $job->cancel();
        } elseif (isset($_POST['retry'])) {
            $newJob = $job->retry((int) ($_SESSION['glpiID'] ?? 0));
            // Return new job id so UI can redirect
            echo json_encode(['redirected_job_id' => $newJob->id()]);
            exit;
        } elseif (isset($_POST['retry_failed_records'])) {
            $purged = $job->retryFailedRecords();
            echo json_encode(['purged_records' => $purged]);
            exit;
        }
    }
}

$includeLogs = isset($_GET['logs']);
echo json_encode(BridgeJob::getStatusPayload($jobId, $includeLogs));

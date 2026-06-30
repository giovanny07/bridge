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
    Session::checkCSRF($_POST, true);
    $job = BridgeJob::getById($jobId);
    if ($job === null) {
        http_response_code(404);
        echo json_encode(['error' => __('Migration job not found.', 'bridge')]);
        exit;
    }

    if (isset($_POST['cancel']) && !$job->isFinished()) {
        $job->cancel();
    } elseif (isset($_POST['retry'])) {
        $newJob = $job->retry((int) ($_SESSION['glpiID'] ?? 0));
        echo json_encode(['redirected_job_id' => $newJob->id()]);
        exit;
    } elseif (isset($_POST['retry_failed_records'])) {
        $purged = $job->retryFailedRecords();
        echo json_encode(['purged_records' => $purged]);
        exit;
    } elseif (isset($_POST['rollback']) && $job->isFinished() && $job->status() !== BridgeJob::STATUS_ROLLED_BACK) {
        try {
            $result = $job->rollback();
            echo json_encode(['rollback_result' => $result]);
        } catch (Throwable $e) {
            http_response_code(400);
            echo json_encode(['error' => $e->getMessage()]);
            exit;
        }
        exit;
    } else {
        http_response_code(400);
        echo json_encode(['error' => __('Invalid job action.', 'bridge')]);
        exit;
    }
}

$includeLogs   = isset($_GET['logs']);
$includeRecent = isset($_GET['recent']);
$payload = BridgeJob::getStatusPayload($jobId, $includeLogs, $includeRecent);
if (isset($payload['error'])) {
    http_response_code(404);
}
echo json_encode($payload);

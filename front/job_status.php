<?php

use GlpiPlugin\Bridge\Connection;
use GlpiPlugin\Bridge\Migration\BridgeJob;
use GlpiPlugin\Bridge\Page\JobStatusPage;
use GlpiPlugin\Bridge\Profile;

Profile::checkMigrate(READ);

$jobId = (int) ($_GET['job_id'] ?? 0);
if ($jobId <= 0) {
    Session::addMessageAfterRedirect(__('Job not found.', 'bridge'), false, ERROR);
    Html::redirect(Connection::getConfigURL());
}

$job = BridgeJob::getById($jobId);
if ($job === null) {
    Session::addMessageAfterRedirect(__('Job not found.', 'bridge'), false, ERROR);
    Html::redirect(Connection::getConfigURL());
}

$connection = new Connection();
if (!$connection->getFromDB($job->connectionId())) {
    Session::addMessageAfterRedirect(__('Connection not found.', 'bridge'), false, ERROR);
    Html::redirect(Connection::getConfigURL());
}

Html::header(__('Migration job', 'bridge'), '', 'config', 'plugins');

try {
    JobStatusPage::show($job, $connection);
} catch (Throwable $e) {
    echo '<div class="alert alert-danger m-3">' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</div>';
}

Html::footer();

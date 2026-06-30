<?php

use GlpiPlugin\Bridge\Connection;
use GlpiPlugin\Bridge\Connector\ConnectorFactory;
use GlpiPlugin\Bridge\Migration\IncidentMapper;
use GlpiPlugin\Bridge\Page\DryRunPage;
use GlpiPlugin\Bridge\Profile;
use GlpiPlugin\Bridge\Resolver\GlpiResolver;

Profile::checkMigrate(READ);

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

Html::header(__('Dry-run', 'bridge'), '', 'config', 'plugins');

try {
    $client       = ConnectorFactory::make($connection);
    $resourceTypes = $client->getResourceTypes();
    $_frontDir    = Connection::getPluginBaseURL() . '/front';
    $dryRunUrl    = $_frontDir . '/dryrun.php';
    $resourceType = (string) ($_POST['resource_type'] ?? '');

    // ── Step 1: no resource type selected → show selector ────────────────
    if ($resourceType === '' || !isset($resourceTypes[$resourceType])) {
        DryRunPage::showSelector($connection, $resourceTypes, $dryRunUrl);
        Html::footer();
        exit;
    }

    // ── Step 2a: type not implemented → show message ──────────────────────
    if (!($resourceTypes[$resourceType]['implemented'] ?? false)) {
        DryRunPage::showNotImplemented($connection, $resourceTypes[$resourceType]['label'] ?? $resourceType);
        Html::footer();
        exit;
    }

    // ── Step 2b: implemented → run dry-run ───────────────────────────────
    $normalizer = ConnectorFactory::makeNormalizer((string) $connection->fields['system_type']);
    $resolver   = GlpiResolver::create();
    $mapper     = new IncidentMapper(
        $resolver,
        $normalizer,
        (int) $connection->fields['entities_id'],
        (int) ($connection->fields['default_groups_id'] ?? 0)
    );

    // Route by resource type (read-only sample for preview)
    $scan = match ($resourceType) {
        'problems'  => $client->listProblems([], 1, 20),
        'changes'   => $client->listChanges([], 1, 20),
        'incidents' => $client->scanIncidents(20),
        default     => throw new RuntimeException("No scanner for: $resourceType"),
    };

    $results = [];
    foreach ($scan['records'] as $record) {
        $results[] = $mapper->map($record, [], $resourceType);
    }

    DryRunPage::show(
        $connection,
        $results,
        $scan['total'],
        $resourceType,
        $_frontDir . '/migrate.php'
    );
} catch (Throwable $e) {
    echo '<div class="alert alert-danger m-3">';
    echo htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
    echo '</div>';
    echo '<div class="m-3"><a class="btn btn-secondary" href="' . htmlspecialchars(Connection::getConfigURL($id), ENT_QUOTES, 'UTF-8') . '">';
    echo __('Back', 'bridge') . '</a></div>';
}

Html::footer();

<?php

use GlpiPlugin\Bridge\Connection;
use GlpiPlugin\Bridge\Connector\ConnectorFactory;
use GlpiPlugin\Bridge\Migration\IncidentMapper;
use GlpiPlugin\Bridge\Page\DryRunPage;
use GlpiPlugin\Bridge\Resolver\GlpiResolver;

Session::checkRight('config', UPDATE);

$id         = (int) ($_POST['id'] ?? 0);
$connection = new Connection();

if (!$id || !$connection->getFromDB($id)) {
    Session::addMessageAfterRedirect(__('Connection not found.', 'bridge'), false, ERROR);
    Html::redirect(Connection::getConfigURL());
}

Html::header(__('Dry-run', 'bridge'), '', 'config', 'plugins');

try {
    $client     = ConnectorFactory::make($connection);
    $normalizer = ConnectorFactory::makeNormalizer((string) $connection->fields['system_type']);
    $scan       = $client->scanIncidents(20);

    $resolver = GlpiResolver::create();
    $mapper   = new IncidentMapper(
        $resolver,
        $normalizer,
        (int) $connection->fields['entities_id'],
        (int) ($connection->fields['default_groups_id'] ?? 0)
    );

    $results = [];
    foreach ($scan['records'] as $incident) {
        $results[] = $mapper->map($incident);
    }

    DryRunPage::show($connection, $results, $scan['total']);
} catch (Throwable $e) {
    echo '<div class="alert alert-danger m-3">';
    echo htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
    echo '</div>';
    echo '<div class="m-3"><a class="btn btn-secondary" href="' . htmlspecialchars(Connection::getConfigURL($id), ENT_QUOTES, 'UTF-8') . '">';
    echo __('Back', 'bridge') . '</a></div>';
}

Html::footer();

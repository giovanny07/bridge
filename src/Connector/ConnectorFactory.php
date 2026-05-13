<?php

namespace GlpiPlugin\Bridge\Connector;

use GlpiPlugin\Bridge\Connection;
use GlpiPlugin\Bridge\Contract\ConnectorInterface;
use GlpiPlugin\Bridge\Contract\NormalizerInterface;
use GlpiPlugin\Bridge\Connector\SolarWinds\SamanageNormalizer;
use GlpiPlugin\Bridge\Connector\SolarWinds\SolarWindsClient;
use RuntimeException;

/**
 * Creates the right connector and normalizer for a given system_type.
 *
 * To add a new source system:
 *   1. Implement ConnectorInterface in src/Connector/{System}/{System}Client.php
 *   2. Implement NormalizerInterface in src/Connector/{System}/{System}Normalizer.php
 *   3. Add a case to make() and makeNormalizer() below.
 *   4. Register Connection::TYPE_{SYSTEM} in Connection::getSupportedSystems().
 */
class ConnectorFactory
{
    public static function make(Connection $connection): ConnectorInterface
    {
        return match ((string) ($connection->fields['system_type'] ?? '')) {
            Connection::TYPE_SOLARWINDS => SolarWindsClient::fromConnection($connection),
            default => throw new RuntimeException(
                'Unsupported source system: ' . ($connection->fields['system_type'] ?? '(empty)')
            ),
        };
    }

    public static function makeNormalizer(string $systemType): NormalizerInterface
    {
        return match ($systemType) {
            Connection::TYPE_SOLARWINDS => new SamanageNormalizer(),
            default => throw new RuntimeException(
                'No normalizer registered for system: ' . $systemType
            ),
        };
    }
}

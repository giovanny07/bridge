<?php

namespace GlpiPlugin\Bridge\Contract;

use GlpiPlugin\Bridge\Connection;

/**
 * Contract that every source-system connector must fulfil.
 *
 * Adding a new system (e.g. Jira, ServiceNow) means implementing this
 * interface and registering it in ConnectorFactory — nothing else changes.
 */
interface ConnectorInterface
{
    /**
     * Lightweight connectivity check. Never throws.
     * Returns: ['ok' => bool, 'status' => int, 'latency_ms' => int, 'total' => int, 'message' => string]
     */
    public function testConnection(): array;

    /**
     * Fetches a small sample of incidents for discovery (always page 1).
     * Returns: ['endpoint', 'status_code', 'total', 'count', 'records']
     */
    public function scanIncidents(int $limit = 10): array;

    /**
     * Paginated incident listing for migration.
     * $filters are system-specific (e.g. ['state' => 'Closed', 'created_after' => '2026-01-01']).
     * Returns: ['endpoint', 'status_code', 'total', 'page', 'per_page', 'count', 'records']
     */
    public function listIncidents(array $filters = [], int $page = 1, int $perPage = 50): array;

    /**
     * Returns all resource types this connector is aware of and whether
     * each one is implemented for migration.
     *
     * Shape: [ 'incidents' => ['label' => 'Incidents', 'implemented' => true], ... ]
     *
     * Unimplemented entries appear in the selector UI as disabled with a
     * "not implemented yet" badge so users know what's coming.
     */
    public function getResourceTypes(): array;

    /**
     * Fetches all comments for a single incident.
     * Returns an array of raw comment objects from the source system.
     */
    public function getIncidentComments(int $incidentId): array;

    /** Factory method — builds the connector from a stored Connection record. */
    public static function fromConnection(Connection $connection): static;
}

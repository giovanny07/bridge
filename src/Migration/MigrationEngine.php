<?php

namespace GlpiPlugin\Bridge\Migration;

use GlpiPlugin\Bridge\Contract\ConnectorInterface;
use GlpiPlugin\Bridge\Contract\NormalizerInterface;
use GlpiPlugin\Bridge\Resolver\GlpiResolver;

/**
 * Orchestrates the migration of source records into GLPI tickets.
 *
 * Each run is idempotent: records already migrated successfully are
 * skipped via MigrationRecord deduplication. Failed records are retried
 * on the next run (or after a manual purge).
 *
 * Options array keys:
 *   limit               int    Max records to migrate in this run (default 50)
 *   start_page          int    First API page to fetch (default 1)
 *   source_ids          string Comma-separated source IDs — overrides filters/pagination
 *   state               string Source state filter (e.g. 'Closed'), empty = all
 *   created_after       string YYYY-MM-DD, filter by creation date
 *   updated_after       string YYYY-MM-DD, filter by last update date
 *   include_comments    bool   Migrate comments as ITILFollowup (default true)
 *   include_attachments bool   Download and attach files (default false)
 *   dry_run             bool   Preview only, nothing written to GLPI (default false)
 */
class MigrationEngine
{
    private const PER_PAGE     = 50;
    private const STATUS_SOLVED = 5;
    private const STATUS_CLOSED = 6;

    public function __construct(
        private readonly ConnectorInterface  $connector,
        private readonly NormalizerInterface $normalizer,
        private readonly GlpiResolver        $resolver,
        private readonly int                 $connectionId,
        private readonly int                 $fallbackEntityId,
        private readonly int                 $fallbackGroupId,
        private readonly int                 $fallbackRequesterId = 0,
    ) {}

    public function run(array $options): MigrationResult
    {
        $limit       = max(1, (int) ($options['limit'] ?? 50));
        $includeComm    = (bool) ($options['include_comments'] ?? true);
        $includeAtt     = (bool) ($options['include_attachments'] ?? false);
        $keepPrivate    = (bool) ($options['keep_private_comments'] ?? false);
        $isDryRun    = (bool) ($options['dry_run'] ?? false);
        $startPage   = max(1, (int) ($options['start_page'] ?? 1));
        $rawIds      = trim((string) ($options['source_ids'] ?? ''));
        $sourceIds   = $rawIds !== '' ? array_values(array_filter(array_map('trim', explode(',', $rawIds)))) : [];

        $mapper = new IncidentMapper(
            $this->resolver,
            $this->normalizer,
            $this->fallbackEntityId,
            $this->fallbackGroupId,
            $this->fallbackRequesterId,
        );

        $result           = new MigrationResult();
        $result->isDryRun = $isDryRun;

        if (!empty($sourceIds)) {
            foreach ($sourceIds as $rawId) {
                try {
                    $incident = $this->connector->getIncident((int) $rawId);
                } catch (\Throwable $e) {
                    $result->addFailed(['id' => $rawId, 'number' => $rawId, 'name' => "ID $rawId"], $e->getMessage());
                    continue;
                }
                $this->processIncident($incident, $mapper, $includeComm, $includeAtt, $keepPrivate, $isDryRun, $result);
            }
            return $result;
        }

        $filters = $this->buildFilters($options);
        $page    = $startPage;

        while ($result->total() < $limit) {
            $remaining = $limit - $result->total();
            $perPage   = min(self::PER_PAGE, $remaining);

            $batch = $this->connector->listIncidents($filters, $page, $perPage);

            if (empty($batch['records'])) {
                break;
            }

            foreach ($batch['records'] as $incident) {
                if ($result->total() >= $limit) {
                    break;
                }
                $this->processIncident($incident, $mapper, $includeComm, $includeAtt, $keepPrivate, $isDryRun, $result);
            }

            if (count($batch['records']) < $perPage) {
                break;
            }

            $page++;
        }

        return $result;
    }

    // ------------------------------------------------------------------ //
    // Per-incident processing
    // ------------------------------------------------------------------ //

    private function processIncident(
        array           $incident,
        IncidentMapper  $mapper,
        bool            $includeComm,
        bool            $includeAtt,
        bool            $keepPrivate,
        bool            $isDryRun,
        MigrationResult $result
    ): void {
        $sourceId = (string) ($incident['id'] ?? '');

        if (!$isDryRun && MigrationRecord::isAlreadyMigrated($this->connectionId, 'incidents', $sourceId)) {
            $result->addSkipped($incident);
            return;
        }

        $comments = [];
        if ($includeComm) {
            try {
                $comments = $this->connector->getIncidentComments((int) $sourceId);
            } catch (\Throwable) {
            }
        }

        $mapped = $mapper->map($incident, $comments);

        if ($isDryRun) {
            $result->addCreated($incident, 0);
            return;
        }

        if (!$mapped->isCreatable()) {
            $error = 'Unresolved entity — configure a fallback entity in the connection settings.';
            $result->addFailed($incident, $error);
            MigrationRecord::log($this->connectionId, 'incidents', $sourceId, (string) ($incident['number'] ?? ''), MigrationRecord::STATUS_FAILED, 0, $error);
            return;
        }

        try {
            $ticketId = $this->createTicket($mapped, $includeAtt, $keepPrivate);
            $result->addCreated($incident, $ticketId);
            MigrationRecord::log($this->connectionId, 'incidents', $sourceId, (string) ($incident['number'] ?? ''), MigrationRecord::STATUS_SUCCESS, $ticketId);
        } catch (\Throwable $e) {
            $result->addFailed($incident, $e->getMessage());
            MigrationRecord::log($this->connectionId, 'incidents', $sourceId, (string) ($incident['number'] ?? ''), MigrationRecord::STATUS_FAILED, 0, $e->getMessage());
        }
    }

    // ------------------------------------------------------------------ //
    // GLPI record creation
    // ------------------------------------------------------------------ //

    private function createTicket(MappedIncident $mapped, bool $includeAttachments, bool $keepPrivate = false): int
    {
        $t      = $mapped->ticket;
        $ticket = new \Ticket();

        $ticketId = (int) $ticket->add(array_merge($t, [
            'urgency'       => $t['priority'] ?? 3,
            'impact'        => $t['priority'] ?? 3,
            '_disablenotif' => true,
        ]));

        if ($ticketId <= 0) {
            throw new \RuntimeException('Ticket::add() returned ' . $ticketId . '. Check GLPI logs.');
        }

        $entityId = (int) ($t['entities_id'] ?? 0);

        foreach ($mapped->followups as $f) {
            $this->createFollowup($f, $ticketId, $entityId, $includeAttachments, $keepPrivate);
        }

        // Solution must be created after followups so it appears last
        if ($mapped->solution !== null) {
            $this->createSolution($mapped->solution, $ticketId);
        }

        // GLPI's ITILSolution::add() may reset ticket to SOLVED (5) and put the
        // solution into WAITING (2) when entity-level validation is configured.
        // For a migration we force the exact source status and accept the solution.
        $targetStatus = (int) ($t['status'] ?? 1);
        if ($targetStatus === self::STATUS_SOLVED || $targetStatus === self::STATUS_CLOSED) {
            $this->forceTicketFinalStatus($ticketId, $targetStatus, $t);
        }

        // Assign actors directly — GLPI's updateActors() validates users against
        // User::getSqlSearchResult() which filters out users without a profile in
        // the ticket's entity. Imported users fail this check silently.
        $this->forceAssignActors($ticketId, $t);

        return $ticketId;
    }

    /**
     * Inserts ticket actors directly, bypassing GLPI's entity-profile validation.
     * GLPI rejects users without a profile in the ticket's entity, which excludes
     * most imported users. Direct DB insert is the correct approach for migration.
     */
    private function forceAssignActors(int $ticketId, array $ticketFields): void
    {
        global $DB;

        $requesterId = (int) ($ticketFields['_users_id_requester'] ?? 0);
        $assigneeId  = (int) ($ticketFields['_users_id_assign']    ?? 0);
        $groupId     = (int) ($ticketFields['_groups_id_assign']   ?? 0);

        // Remove default actors GLPI added during creation (e.g. session user as requester)
        $DB->delete('glpi_tickets_users',  ['tickets_id' => $ticketId]);
        $DB->delete('glpi_groups_tickets', ['tickets_id' => $ticketId]);

        if ($requesterId > 0) {
            $DB->insert('glpi_tickets_users', [
                'tickets_id'        => $ticketId,
                'users_id'          => $requesterId,
                'type'              => 1, // REQUESTER
                'use_notification'  => 0,
                'alternative_email' => '',
            ]);
        }

        if ($assigneeId > 0) {
            $DB->insert('glpi_tickets_users', [
                'tickets_id'        => $ticketId,
                'users_id'          => $assigneeId,
                'type'              => 2, // ASSIGN
                'use_notification'  => 0,
                'alternative_email' => '',
            ]);
        }

        if ($groupId > 0) {
            $DB->insert('glpi_groups_tickets', [
                'tickets_id' => $ticketId,
                'groups_id'  => $groupId,
                'type'       => 2, // ASSIGN
            ]);
        }
    }

    /**
     * Forces the correct status/dates on a ticket after GLPI business-rule
     * overrides. Also force-accepts any solution that got stuck in WAITING.
     */
    private function forceTicketFinalStatus(int $ticketId, int $status, array $ticketFields): void
    {
        global $DB;

        $update = ['status' => $status];

        if (!empty($ticketFields['solvedate'])) {
            $update['solvedate'] = $ticketFields['solvedate'];
        }
        if ($status === self::STATUS_CLOSED) {
            $update['closedate'] = $ticketFields['closedate']
                ?? $ticketFields['solvedate']
                ?? $ticketFields['date']
                ?? date('Y-m-d H:i:s');
        }

        $DB->update('glpi_tickets', $update, ['id' => $ticketId]);

        // Accept any solution that validation rules set to WAITING
        $DB->update(
            'glpi_itilsolutions',
            ['status' => 3],
            ['items_id' => $ticketId, 'itemtype' => 'Ticket']
        );
    }

    private function createFollowup(array $f, int $ticketId, int $entityId, bool $includeAttachments, bool $keepPrivate = false): void
    {
        $followup = new \ITILFollowup();
        $historicalDate = $f['date'] ?? date('Y-m-d H:i:s');
        $fId = (int) $followup->add([
            'itemtype'        => 'Ticket',
            'items_id'        => $ticketId,
            'content'         => $f['content'],
            'date'            => $historicalDate,
            'date_creation'   => $historicalDate,
            'is_private'      => ($keepPrivate && $f['is_private']) ? 1 : 0,
            'users_id'        => $f['_users_id'] ?? 0,
            'requesttypes_id' => 6,
            '_disablenotif'   => true,
        ]);

        if ($includeAttachments && $fId > 0) {
            $this->attachFilesFromComment($f, $ticketId, $fId, $entityId);
        }
    }

    private function createSolution(array $solution, int $ticketId): void
    {
        $s = new \ITILSolution();
        $s->add([
            'itemtype'      => 'Ticket',
            'items_id'      => $ticketId,
            'content'       => $solution['content'] ?? '',
            'date_creation' => $solution['date'] ?? date('Y-m-d H:i:s'),
            'users_id'      => $solution['_users_id'] ?? 0,
            'status'        => 3, // ITILValidation::ACCEPTED
            '_disablenotif' => true,
        ]);
    }

    private function attachFilesFromComment(array $followup, int $ticketId, int $followupId, int $entityId): void
    {
        $allAttachments = array_merge(
            $followup['_attachments']         ?? [],
            $followup['_inline_attachments']  ?? [],
            $followup['_shared_attachments']  ?? []
        );

        foreach ($allAttachments as $att) {
            $url = (string) ($att['url'] ?? $att['download_url'] ?? '');
            if ($url === '') {
                continue;
            }
            $this->downloadAndLink($url, $ticketId, $followupId, $entityId);
        }
    }

    private function downloadAndLink(string $url, int $ticketId, int $followupId, int $entityId): void
    {
        $file = $this->connector->downloadAttachment($url);
        if ($file === null) {
            return;
        }

        $tmpName = uniqid('bridge_') . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $file['filename']);
        $tmpPath = GLPI_TMP_DIR . '/' . $tmpName;

        if (file_put_contents($tmpPath, $file['content']) === false) {
            return;
        }

        $doc   = new \Document();
        $docId = (int) $doc->add([
            'name'             => $file['filename'],
            'entities_id'      => $entityId,
            '_filename'        => [$tmpName],
            '_prefix_filename' => [''],
        ]);

        @unlink($tmpPath);

        if ($docId <= 0) {
            return;
        }

        // Link to ticket
        $di = new \Document_Item();
        $di->add(['documents_id' => $docId, 'itemtype' => 'Ticket',        'items_id' => $ticketId,   'entities_id' => $entityId]);
        // Link to followup
        $di->add(['documents_id' => $docId, 'itemtype' => 'ITILFollowup', 'items_id' => $followupId, 'entities_id' => $entityId]);
    }

    // ------------------------------------------------------------------ //
    // Filter builder
    // ------------------------------------------------------------------ //

    private function buildFilters(array $options): array
    {
        $filters = [];

        if (!empty($options['state'])) {
            $filters['state'] = $options['state'];
        }
        if (!empty($options['created_after'])) {
            $filters['created_after'] = $options['created_after'];
            // Sort oldest-first so created_after retrieves historical records,
            // not the most recent ones (Samanage default is newest-first).
            $filters['sort_by']    = 'created_at';
            $filters['sort_order'] = 'asc';
        }
        if (!empty($options['updated_after'])) {
            $filters['updated_after'] = $options['updated_after'];
        }

        return $filters;
    }
}

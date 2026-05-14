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
 *   state               string Source state filter (e.g. 'Closed'), empty = all
 *   created_after       string YYYY-MM-DD, filter by creation date
 *   updated_after       string YYYY-MM-DD, filter by last update date
 *   include_comments    bool   Migrate comments as ITILFollowup (default true)
 *   include_attachments bool   Download and attach files (default false)
 *   dry_run             bool   Preview only, nothing written to GLPI (default false)
 */
class MigrationEngine
{
    private const PER_PAGE = 50;

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
        $includeComm = (bool) ($options['include_comments'] ?? true);
        $includeAtt  = (bool) ($options['include_attachments'] ?? false);
        $isDryRun    = (bool) ($options['dry_run'] ?? false);
        $filters     = $this->buildFilters($options);

        $mapper = new IncidentMapper(
            $this->resolver,
            $this->normalizer,
            $this->fallbackEntityId,
            $this->fallbackGroupId,
            $this->fallbackRequesterId,
        );

        $result          = new MigrationResult();
        $result->isDryRun = $isDryRun;
        $page            = 1;

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

                $sourceId = (string) ($incident['id'] ?? '');

                // Dedup — skip if already successfully migrated
                if (!$isDryRun && MigrationRecord::isAlreadyMigrated($this->connectionId, 'incidents', $sourceId)) {
                    $result->addSkipped($incident);
                    continue;
                }

                // Fetch comments (non-fatal if it fails)
                $comments = [];
                if ($includeComm) {
                    try {
                        $comments = $this->connector->getIncidentComments((int) $sourceId);
                    } catch (\Throwable) {
                    }
                }

                $mapped = $mapper->map($incident, $comments);

                if ($isDryRun) {
                    // In dry-run just count as "would create"
                    $result->addCreated($incident, 0);
                    continue;
                }

                if (!$mapped->isCreatable()) {
                    $error = 'Unresolved entity — configure a fallback entity in the connection settings.';
                    $result->addFailed($incident, $error);
                    MigrationRecord::log($this->connectionId, 'incidents', $sourceId, (string) ($incident['number'] ?? ''), MigrationRecord::STATUS_FAILED, 0, $error);
                    continue;
                }

                try {
                    $ticketId = $this->createTicket($mapped, $includeAtt);
                    $result->addCreated($incident, $ticketId);
                    MigrationRecord::log($this->connectionId, 'incidents', $sourceId, (string) ($incident['number'] ?? ''), MigrationRecord::STATUS_SUCCESS, $ticketId);
                } catch (\Throwable $e) {
                    $result->addFailed($incident, $e->getMessage());
                    MigrationRecord::log($this->connectionId, 'incidents', $sourceId, (string) ($incident['number'] ?? ''), MigrationRecord::STATUS_FAILED, 0, $e->getMessage());
                }
            }

            if (count($batch['records']) < $perPage) {
                break; // Last page reached
            }

            $page++;
        }

        return $result;
    }

    // ------------------------------------------------------------------ //
    // GLPI record creation
    // ------------------------------------------------------------------ //

    private function createTicket(MappedIncident $mapped, bool $includeAttachments): int
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
            $this->createFollowup($f, $ticketId, $entityId, $includeAttachments);
        }

        // Solution must be created after followups so it appears last
        if ($mapped->solution !== null) {
            $this->createSolution($mapped->solution, $ticketId);
        }

        return $ticketId;
    }

    private function createFollowup(array $f, int $ticketId, int $entityId, bool $includeAttachments): void
    {
        $followup = new \ITILFollowup();
        $fId = (int) $followup->add([
            'itemtype'        => 'Ticket',
            'items_id'        => $ticketId,
            'content'         => $f['content'],
            'date'            => $f['date'] ?? date('Y-m-d H:i:s'),
            'is_private'      => $f['is_private'] ? 1 : 0,
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

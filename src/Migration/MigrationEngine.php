<?php

namespace GlpiPlugin\Bridge\Migration;

use GlpiPlugin\Bridge\Contract\ConnectorInterface;
use GlpiPlugin\Bridge\Contract\NormalizerInterface;
use GlpiPlugin\Bridge\Resolver\GlpiResolver;
use GlpiPlugin\Bridge\Migration\MigrationCursor;

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

    private int $jobId = 0;

    public function __construct(
        private readonly ConnectorInterface  $connector,
        private readonly NormalizerInterface $normalizer,
        private readonly GlpiResolver        $resolver,
        private readonly int                 $connectionId,
        private readonly int                 $fallbackEntityId,
        private readonly int                 $fallbackGroupId,
        private readonly int                 $fallbackRequesterId = 0,
        private readonly string              $resourceType = 'incidents',
    ) {}

    public function setJobId(int $id): void
    {
        $this->jobId = $id;
    }

    /**
     * Performs a read-only preflight for a real migration request.
     *
     * It fetches a small sample using the selected filters, applies local date
     * filters, checks dedupe against successful migration records, and maps the
     * remaining records. Nothing is written to GLPI.
     */
    public function preflight(array $options, int $sampleLimit = 50): MigrationResult
    {
        $limit     = max(1, min($sampleLimit, (int) ($options['limit'] ?? 50)));
        $startPage = max(1, (int) ($options['start_page'] ?? 1));
        $rawIds    = trim((string) ($options['source_ids'] ?? ''));
        $sourceIds = $rawIds !== '' ? array_values(array_filter(array_map('trim', explode(',', $rawIds)))) : [];

        $mapper = new IncidentMapper(
            $this->resolver,
            $this->normalizer,
            $this->fallbackEntityId,
            $this->fallbackGroupId,
            $this->fallbackRequesterId,
        );

        $result           = new MigrationResult();
        $result->isDryRun = true;

        if ($sourceIds !== []) {
            $records = [];
            foreach ($sourceIds as $rawId) {
                $rawId = ltrim(trim($rawId), '#');
                try {
                    $records[] = $result->measureStat('time_api_ms', fn() => $this->getRecordBySourceId($rawId));
                    $result->incStat('api_pages');
                    $result->incStat('scanned');
                } catch (\Throwable $e) {
                    $failedRecord = ['id' => $rawId, 'number' => $rawId, 'name' => "#$rawId"];
                    $result->addFailed($failedRecord, $e->getMessage());
                    $result->addPreflightRow($failedRecord, 'failed', [], $e->getMessage());
                }
            }
            $this->preflightRecords($records, $options, $mapper, $result, $limit);
            return $result;
        }

        $filters = $this->buildFilters($options);
        $perPage = min(self::PER_PAGE, max(1, $limit));
        $chronologicalFromDate = !empty($options['created_after']) && $startPage === 1;
        $page = $chronologicalFromDate
            ? $this->findCreatedAfterBoundaryPage($filters, (string) $options['created_after'], $perPage, $result)
            : $startPage;

        $batch = $result->measureStat(
            'time_api_ms',
            fn() => $this->listBatch($filters, $page, $perPage)
        );
        $result->incStat('api_pages');
        $result->incStat('scanned', count($batch['records'] ?? []));

        $records = $chronologicalFromDate
            ? array_reverse($batch['records'] ?? [])
            : ($batch['records'] ?? []);
        $this->preflightRecords($records, $options, $mapper, $result, $limit);

        return $result;
    }

    /** Returns the GLPI itemtype for the current resource type. */
    private function glpiItemtype(): string
    {
        return match ($this->resourceType) {
            'problems' => 'Problem',
            'changes'  => 'Change',
            default    => 'Ticket',
        };
    }

    /** Returns the glpi_X_users table name for the current itemtype. */
    private function actorUserTable(): string
    {
        return match ($this->resourceType) {
            'problems' => 'glpi_problems_users',
            'changes'  => 'glpi_changes_users',
            default    => 'glpi_tickets_users',
        };
    }

    /** Returns the glpi_groups_X table name for the current itemtype. */
    private function actorGroupTable(): string
    {
        return match ($this->resourceType) {
            'problems' => 'glpi_groups_problems',
            'changes'  => 'glpi_changes_groups',
            default    => 'glpi_groups_tickets',
        };
    }

    /** Returns the FK column name used in actor tables. */
    private function actorFk(): string
    {
        return match ($this->resourceType) {
            'problems' => 'problems_id',
            'changes'  => 'changes_id',
            default    => 'tickets_id',
        };
    }

    /**
     * Runs the migration engine, optionally resuming from a saved cursor.
     *
     * In from_date (chronological) mode the engine processes at most
     * MigrationCursor::CHUNK_PAGES pages per call to stay within PHP
     * max_execution_time.  The caller is responsible for persisting the
     * returned cursor so the next run continues where this one stopped.
     *
     * @param  MigrationCursor|null $cursor  Existing cursor to resume, or null to start fresh.
     * @return array{0:MigrationResult, 1:MigrationCursor|null}
     */
    public function run(array $options, ?MigrationCursor $cursor = null): array
    {
        $limit       = max(1, (int) ($options['limit'] ?? 50));
        $includeComm = (bool) ($options['include_comments']      ?? true);
        $includeAtt  = (bool) ($options['include_attachments']   ?? false);
        $keepPrivate = (bool) ($options['keep_private_comments'] ?? false);
        $isDryRun    = (bool) ($options['dry_run']               ?? false);
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

        // ── By-IDs mode: no pagination, no cursor ────────────────────────
        if (!empty($sourceIds)) {
            foreach ($sourceIds as $rawId) {
                $rawId = ltrim(trim($rawId), '#');
                try {
                    $record = $this->getRecordBySourceId($rawId);
                } catch (\Throwable $e) {
                    $result->addFailed(['id' => $rawId, 'number' => $rawId, 'name' => "#$rawId"], $e->getMessage());
                    continue;
                }
                $this->processIncident($record, $mapper, $includeComm, $includeAtt, $keepPrivate, $isDryRun, $result);
            }
            return [$result, null];
        }

        // ── Paginated mode ────────────────────────────────────────────────
        $filters = $this->buildFilters($options);
        $perPage = self::PER_PAGE;

        $chronologicalFromDate = !empty($options['created_after']) && $startPage === 1 && $cursor === null;

        // Determine starting page: cursor > explicit start_page > boundary search
        if ($cursor !== null) {
            $page                  = $cursor->currentPage();
            $chronologicalFromDate = $cursor->direction() === 'backward';
        } elseif ($chronologicalFromDate) {
            $page = $this->findCreatedAfterBoundaryPage($filters, (string) $options['created_after'], $perPage, $result);
        } else {
            $page = $startPage;
        }

        // Create cursor for from_date runs (not for dry-runs or manual page mode)
        $useCursor = $chronologicalFromDate && !$isDryRun && $cursor === null;
        if ($useCursor) {
            $cursor = MigrationCursor::create(
                $this->connectionId,
                $this->resourceType,
                MigrationCursor::hashOptions($options),
                $page,
                'backward',
                $page,
                $options
            );
        }

        $pagesThisRun  = 0;
        $maxPagesPerRun = $chronologicalFromDate ? MigrationCursor::CHUNK_PAGES : PHP_INT_MAX;

        while ($this->attemptedTotal($result) < $limit && $pagesThisRun < $maxPagesPerRun) {
            $batch = $result->measureStat(
                'time_api_ms',
                fn() => $this->listBatch($filters, $page, $perPage)
            );
            $result->incStat('api_pages');
            $result->incStat('scanned', count($batch['records'] ?? []));
            $pagesThisRun++;

            if (empty($batch['records'])) {
                break;
            }

            $records = $chronologicalFromDate
                ? array_reverse($batch['records'])
                : $batch['records'];
            [$records, $alreadyMigrated] = $this->prepareBatchRecords($records, $options, $isDryRun, $result);

            foreach ($records as $incident) {
                if ($this->attemptedTotal($result) >= $limit) {
                    break;
                }
                $this->processIncident($incident, $mapper, $includeComm, $includeAtt, $keepPrivate, $isDryRun, $result, $alreadyMigrated);
            }

            if ($chronologicalFromDate) {
                $page--;
                if ($page < 1) {
                    // Exhausted the entire date range
                    if ($cursor !== null) {
                        $cursor->advance(0, count($result->created), (int) ($result->stats['scanned'] ?? 0));
                        $cursor->complete();
                    }
                    break;
                }
            } else {
                if (count($batch['records']) < $perPage) {
                    break;
                }
                $totalPages = (int) ceil(max(0, (int) ($batch['total'] ?? 0)) / $perPage);
                if ($totalPages > 0 && $page >= $totalPages) {
                    break;
                }
                $page++;
            }
        }

        // Persist cursor position after the chunk
        if ($cursor !== null && $cursor->isActive()) {
            $cursor->advance(
                $page,
                count($result->created),
                (int) ($result->stats['scanned'] ?? 0)
            );
            // Mark complete if we reached the limit or exhausted pages
            if ($page < 1 || $this->attemptedTotal($result) >= $limit) {
                $cursor->complete();
            }
        }

        return [$result, $cursor];
    }

    private function getRecordBySourceId(string $rawId): array
    {
        if ($this->resourceType === 'problems') {
            return $this->connector->getProblem((int) $rawId);
        }
        if ($this->resourceType === 'changes') {
            return $this->connector->getChange((int) $rawId);
        }
        if ((int) $rawId < 100_000_000) {
            return $this->connector->getIncidentByNumber((int) $rawId);
        }
        return $this->connector->getIncident((int) $rawId);
    }

    /**
     * @param array<int,array<string,mixed>> $records
     */
    private function preflightRecords(
        array $records,
        array $options,
        IncidentMapper $mapper,
        MigrationResult $result,
        int $limit
    ): void {
        $dateMatched = [];
        foreach ($records as $record) {
            if ($this->matchesLocalDateFilters($record, $options)) {
                $dateMatched[] = $record;
            }
        }
        $result->incStat('date_matched', count($dateMatched));

        $alreadyMigrated = $result->measureStat(
            'time_dedupe_ms',
            fn() => $this->alreadyMigratedForRecords($dateMatched)
        );

        foreach ($dateMatched as $record) {
            if ($result->total() >= $limit) {
                break;
            }

            $sourceId = (string) ($record['id'] ?? '');
            if ($sourceId !== '' && isset($alreadyMigrated[$sourceId])) {
                $result->addSkipped($record);
                $result->incStat('duplicates');
                $result->addPreflightRow($record, 'duplicate', [], 'Already migrated successfully');
                continue;
            }

            $result->incStat('queued');
            $mapped = $result->measureStat(
                'time_map_ms',
                fn() => $mapper->map($record, [], $this->resourceType)
            );
            $result->incStat('mapped');

            if ($mapped->isCreatable()) {
                $result->addCreated($record, 0);
                $result->addPreflightRow($record, $mapped->status, $mapped->warnings);
            } else {
                $reason = 'Unresolved entity — configure a fallback entity in the connection settings.';
                $result->addFailed($record, $reason);
                $result->addPreflightRow($record, 'unresolved', $mapped->warnings, $reason);
            }
        }
    }

    private function listBatch(array $filters, int $page, int $perPage): array
    {
        return match ($this->resourceType) {
            'problems' => $this->connector->listProblems($filters, $page, $perPage),
            'changes'  => $this->connector->listChanges($filters, $page, $perPage),
            default    => $this->connector->listIncidents($filters, $page, $perPage),
        };
    }

    private function findCreatedAfterBoundaryPage(array $filters, string $cutoff, int $perPage, MigrationResult $result): int
    {
        $firstBatch = $result->measureStat(
            'time_api_ms',
            fn() => $this->listBatch($filters, 1, $perPage)
        );
        $totalPages = (int) ceil(max(0, (int) ($firstBatch['total'] ?? 0)) / $perPage);

        if ($totalPages <= 1) {
            return 1;
        }

        $low = 1;
        $high = $totalPages;
        $boundary = 1;

        while ($low <= $high) {
            $mid = intdiv($low + $high, 2);
            $batch = $mid === 1
                ? $firstBatch
                : $result->measureStat('time_api_ms', fn() => $this->listBatch($filters, $mid, $perPage));

            if ($this->batchHasRecordOnOrAfter($batch['records'] ?? [], $cutoff)) {
                $boundary = $mid;
                $low = $mid + 1;
            } else {
                $high = $mid - 1;
            }
        }

        return $boundary;
    }

    private function batchHasRecordOnOrAfter(array $records, string $cutoff): bool
    {
        foreach ($records as $record) {
            if ($this->dateIsOnOrAfter($record['created_at'] ?? null, $cutoff)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Applies local filters and dedupes a full API page before expensive
     * per-ticket work starts.
     *
     * @param array<int,array<string,mixed>> $records
     * @return array{0:array<int,array<string,mixed>>,1:array<string,true>}
     */
    private function prepareBatchRecords(array $records, array $options, bool $isDryRun, MigrationResult $result): array
    {
        $dateMatched = [];
        foreach ($records as $record) {
            if ($this->matchesLocalDateFilters($record, $options)) {
                $dateMatched[] = $record;
            }
        }
        $result->incStat('date_matched', count($dateMatched));

        if ($isDryRun) {
            $result->incStat('queued', count($dateMatched));
            return [$dateMatched, []];
        }

        $alreadyMigrated = $result->measureStat(
            'time_dedupe_ms',
            fn() => $this->alreadyMigratedForRecords($dateMatched)
        );
        if ($alreadyMigrated === []) {
            $result->incStat('queued', count($dateMatched));
            return [$dateMatched, []];
        }

        $queued = [];
        foreach ($dateMatched as $record) {
            $sourceId = (string) ($record['id'] ?? '');
            if ($sourceId !== '' && isset($alreadyMigrated[$sourceId])) {
                $result->addSkipped($record);
                $result->incStat('duplicates');
                continue;
            }
            $queued[] = $record;
        }

        $result->incStat('queued', count($queued));

        return [$queued, []];
    }

    /**
     * @param array<int,array<string,mixed>> $records
     * @return array<string,true>
     */
    private function alreadyMigratedForRecords(array $records): array
    {
        $sourceIds = [];
        foreach ($records as $record) {
            $sourceId = (string) ($record['id'] ?? '');
            if ($sourceId !== '') {
                $sourceIds[] = $sourceId;
            }
        }

        return MigrationRecord::getMigratedSourceIds($this->connectionId, $this->resourceType, $sourceIds);
    }

    private function attemptedTotal(MigrationResult $result): int
    {
        return count($result->created) + count($result->failed);
    }

    private function matchesLocalDateFilters(array $incident, array $options): bool
    {
        if (!empty($options['created_after']) && !$this->dateIsOnOrAfter($incident['created_at'] ?? null, (string) $options['created_after'])) {
            return false;
        }

        if (!empty($options['updated_after']) && !$this->dateIsOnOrAfter($incident['updated_at'] ?? null, (string) $options['updated_after'])) {
            return false;
        }

        return true;
    }

    private function dateIsOnOrAfter(mixed $sourceDate, string $cutoff): bool
    {
        if (!is_string($sourceDate) || trim($sourceDate) === '') {
            return false;
        }

        try {
            $source = new \DateTimeImmutable($sourceDate);
            $limit  = new \DateTimeImmutable($cutoff . ' 00:00:00');
        } catch (\Throwable) {
            return false;
        }

        return $source >= $limit;
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
        MigrationResult $result,
        ?array          $alreadyMigrated = null
    ): void {
        $sourceId = (string) ($incident['id'] ?? '');

        if (!$isDryRun) {
            $isAlreadyMigrated = $alreadyMigrated !== null
                ? isset($alreadyMigrated[$sourceId])
                : MigrationRecord::isAlreadyMigrated($this->connectionId, $this->resourceType, $sourceId);

            if ($isAlreadyMigrated) {
                $result->addSkipped($incident);
                return;
            }
        }

        $mapped = $result->measureStat(
            'time_map_ms',
            fn() => $mapper->map($incident, [], $this->resourceType)
        );
        $result->incStat('mapped');

        if ($isDryRun) {
            $result->addCreated($incident, 0);
            return;
        }

        if (!$mapped->isCreatable()) {
            $error = 'Unresolved entity — configure a fallback entity in the connection settings.';
            $result->addFailed($incident, $error);
            MigrationRecord::log($this->connectionId, $this->resourceType, $sourceId, (string) ($incident['number'] ?? ''), MigrationRecord::STATUS_FAILED, 0, $error, $this->jobId);
            return;
        }

        if ($includeComm) {
            try {
                $comments = $result->measureStat('time_comments_ms', fn() => match ($this->resourceType) {
                    'problems' => $this->connector->getProblemComments((int) $sourceId),
                    'changes'  => $this->connector->getChangeComments((int) $sourceId),
                    default    => $this->connector->getIncidentComments((int) $sourceId),
                });
                $result->incStat('comments_requests');
                $result->incStat('comments_read', count($comments));
                if ($comments !== []) {
                    $mapped = $result->measureStat(
                        'time_map_ms',
                        fn() => $mapper->map($incident, $comments, $this->resourceType)
                    );
                    $result->incStat('mapped');
                }
            } catch (\Throwable) {
            }
        }

        try {
            $ticketId = $result->measureStat(
                'time_ticket_create_ms',
                fn() => $this->createTicket($mapped, $includeAtt, $keepPrivate, $result)
            );
            $result->addCreated($incident, $ticketId);
            $warningsNote = implode(' | ', $mapped->warnings);
            MigrationRecord::log($this->connectionId, $this->resourceType, $sourceId, (string) ($incident['number'] ?? ''), MigrationRecord::STATUS_SUCCESS, $ticketId, $warningsNote, $this->jobId);
        } catch (\Throwable $e) {
            $result->addFailed($incident, $e->getMessage());
            MigrationRecord::log($this->connectionId, $this->resourceType, $sourceId, (string) ($incident['number'] ?? ''), MigrationRecord::STATUS_FAILED, 0, $e->getMessage(), $this->jobId);
        }
    }

    // ------------------------------------------------------------------ //
    // GLPI record creation
    // ------------------------------------------------------------------ //

    private function createTicket(MappedIncident $mapped, bool $includeAttachments, bool $keepPrivate, MigrationResult $result): int
    {
        $t        = $mapped->ticket;
        $itemtype = $this->glpiItemtype();
        $item     = new $itemtype();

        $createInput = array_merge($t, [
            'urgency'       => $t['priority'] ?? 3,
            'impact'        => $t['priority'] ?? 3,
            '_disablenotif' => true,
            '_auto_import'  => true,
        ]);
        // Remove Ticket-only fields not present on Problem or Change
        if ($itemtype !== 'Ticket') {
            unset($createInput['type'], $createInput['requesttypes_id']);
        }
        // Strip the target status so GLPI creates the item at INCOMING (1).
        // If we pass a solved/closed status, GLPI honors it for Changes/Problems
        // and then rejects ITILSolution::add() with "already solved".
        // forceTicketFinalStatus() sets the correct status after the solution.
        unset($createInput['status']);

        $itemId = (int) $item->add($createInput);

        if ($itemId <= 0) {
            throw new \RuntimeException($itemtype . '::add() returned ' . $itemId . '. Check GLPI logs.');
        }
        $result->incStat('tickets_created');

        $entityId = (int) ($t['entities_id'] ?? 0);

        foreach ($mapped->followups as $f) {
            $this->createFollowup($f, $itemId, $entityId, $includeAttachments, $keepPrivate, $itemtype, $result);
        }

        // Workaround (Problems only) — stored as a private internal followup
        if ($itemtype === 'Problem') {
            // Keep the original HTML — strip only <a> links (already done in
            // problemToITIL), do NOT strip_tags + re-encode (causes double-encoding)
            $workaround = trim((string) ($t['_workaround'] ?? ''));
            if ($workaround !== '' && strip_tags($workaround) !== '') {
                $wf = new \ITILFollowup();
                $wf->add([
                    'itemtype'        => 'Problem',
                    'items_id'        => $itemId,
                    'content'         => '<p><strong>Workaround:</strong></p>' . $workaround,
                    'date'            => $t['date'] ?? date('Y-m-d H:i:s'),
                    'date_creation'   => $t['date'] ?? date('Y-m-d H:i:s'),
                    'is_private'      => 1,
                    'users_id'        => 0,
                    'requesttypes_id' => 6,
                    '_disablenotif'   => true,
                ]);
            }
        }

        // Solution must come after followups
        if ($mapped->solution !== null) {
            $this->createSolution($mapped->solution, $itemId, $itemtype);
        }

        // Always force the correct status: since we stripped it from createInput,
        // the item was created at INCOMING (1) regardless of the source status.
        $targetStatus = (int) ($t['status'] ?? 1);
        $this->forceTicketFinalStatus($itemId, $targetStatus, $t, $itemtype);

        $this->forceAssignActors($itemId, $t);

        return $itemId;
    }

    /**
     * Inserts ticket actors directly, bypassing GLPI's entity-profile validation.
     * GLPI rejects users without a profile in the ticket's entity, which excludes
     * most imported users. Direct DB insert is the correct approach for migration.
     */
    private function forceAssignActors(int $ticketId, array $ticketFields): void
    {
        global $DB;

        $requesterId    = (int)    ($ticketFields['_users_id_requester']  ?? 0);
        $requesterEmail = (string) ($ticketFields['_requester_alt_email'] ?? '');
        $assigneeId     = (int)    ($ticketFields['_users_id_assign']     ?? 0);
        $groupId        = (int)    ($ticketFields['_groups_id_assign']    ?? 0);
        $fk             = $this->actorFk();
        $userTable      = $this->actorUserTable();
        $groupTable     = $this->actorGroupTable();

        // Remove default actors GLPI added during creation (e.g. session user as requester)
        $DB->delete($userTable,  [$fk => $ticketId]);
        $DB->delete($groupTable, [$fk => $ticketId]);

        if ($requesterId > 0) {
            $DB->insert($userTable, [
                $fk                 => $ticketId,
                'users_id'          => $requesterId,
                'type'              => 1,
                'use_notification'  => 0,
                'alternative_email' => '',
            ]);
        } elseif ($requesterEmail !== '') {
            $DB->insert($userTable, [
                $fk                 => $ticketId,
                'users_id'          => 0,
                'type'              => 1,
                'use_notification'  => 1,
                'alternative_email' => $requesterEmail,
            ]);
        }

        if ($assigneeId > 0) {
            $DB->insert($userTable, [
                $fk                 => $ticketId,
                'users_id'          => $assigneeId,
                'type'              => 2,
                'use_notification'  => 0,
                'alternative_email' => '',
            ]);
        }

        if ($groupId > 0) {
            $DB->insert($groupTable, [
                $fk          => $ticketId,
                'groups_id'  => $groupId,
                'type'       => 2,
            ]);
        }
    }

    /**
     * Forces the correct status/dates on a ticket after GLPI business-rule
     * overrides. Also force-accepts any solution that got stuck in WAITING.
     */
    private function forceTicketFinalStatus(int $ticketId, int $status, array $ticketFields, string $itemtype = 'Ticket'): void
    {
        global $DB;

        $table  = match ($itemtype) {
            'Problem' => 'glpi_problems',
            'Change'  => 'glpi_changes',
            default   => 'glpi_tickets',
        };
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

        $DB->update($table, $update, ['id' => $ticketId]);

        $DB->update(
            'glpi_itilsolutions',
            ['status' => 3],
            ['items_id' => $ticketId, 'itemtype' => $itemtype]
        );
    }

    private function createFollowup(
        array $f,
        int $ticketId,
        int $entityId,
        bool $includeAttachments,
        bool $keepPrivate,
        string $itemtype,
        MigrationResult $result
    ): void
    {
        global $DB;

        $followup       = new \ITILFollowup();
        $historicalDate = $f['date'] ?? date('Y-m-d H:i:s');
        $fId = (int) $result->measureStat('time_followups_ms', fn() => $followup->add([
            'itemtype'        => $itemtype,
            'items_id'        => $ticketId,
            'content'         => $f['content'],
            'date'            => $historicalDate,
            'date_creation'   => $historicalDate,
            'is_private'      => ($keepPrivate && $f['is_private']) ? 1 : 0,
            'users_id'        => $f['_users_id'] ?? 0,
            'requesttypes_id' => 6,
            '_disablenotif'   => true,
        ]));
        if ($fId > 0) {
            $result->incStat('followups_created');
        }

        if ($includeAttachments && $fId > 0) {
            $inlineAttachments = $f['_inline_attachments'] ?? [];
            $fileAttachments   = array_merge($f['_attachments'] ?? [], $f['_shared_attachments'] ?? []);
            $result->incStat('attachments_detected', count($inlineAttachments) + count($fileAttachments));

            // Inline images: download → create Document → build src replacement map
            $inlineUrlMap = $this->attachInlineImages($inlineAttachments, $fId, $entityId, $result);

            // Replace broken SolarWinds src URLs in the followup HTML
            if (!empty($inlineUrlMap)) {
                $updatedContent = str_replace(array_keys($inlineUrlMap), array_values($inlineUrlMap), $f['content']);
                if ($updatedContent !== $f['content']) {
                    $DB->update('glpi_itilfollowups', ['content' => $updatedContent], ['id' => $fId]);
                }
            }

            // Regular + shared attachments (no HTML replacement needed)
            foreach ($fileAttachments as $att) {
                $url = (string) ($att['url'] ?? $att['download_url'] ?? '');
                if ($url !== '') {
                    $this->downloadAndLink($url, $ticketId, $fId, $entityId, $result);
                }
            }
        }
    }

    /**
     * Downloads inline images, creates GLPI Documents, and returns a map of
     * original SolarWinds URL → GLPI document URL for HTML src replacement.
     */
    private function attachInlineImages(array $inlineAtts, int $followupId, int $entityId, MigrationResult $result): array
    {
        global $CFG_GLPI;

        $urlMap = [];

        foreach ($inlineAtts as $att) {
            $originalUrl = (string) ($att['url'] ?? $att['download_url'] ?? '');
            if ($originalUrl === '') {
                continue;
            }

            $file = $result->measureStat('time_attachments_ms', fn() => $this->connector->downloadAttachment($originalUrl));
            if ($file === null) {
                $result->incStat('attachments_failed');
                continue;
            }

            $tmpName = uniqid('bridge_') . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $file['filename']);
            $tmpPath = GLPI_TMP_DIR . '/' . $tmpName;
            if (file_put_contents($tmpPath, $file['content']) === false) {
                $result->incStat('attachments_failed');
                continue;
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
                $result->incStat('attachments_failed');
                continue;
            }
            $result->incStat('attachments_downloaded');

            $di = new \Document_Item();
            $di->add(['documents_id' => $docId, 'itemtype' => 'ITILFollowup', 'items_id' => $followupId, 'entities_id' => $entityId]);
            $result->incStat('documents_linked');

            // Map the original URL to the GLPI document endpoint
            $glpiUrl = ($CFG_GLPI['url_base'] ?? '') . '/front/document.send.php?docid=' . $docId;
            $urlMap[$originalUrl] = $glpiUrl;

            // Also map the path-only version so both relative and absolute src attrs get replaced
            $path = (string) parse_url($originalUrl, PHP_URL_PATH);
            if ($path !== '' && $path !== $originalUrl) {
                $urlMap[$path] = $glpiUrl;
            }
        }

        return $urlMap;
    }

    private function createSolution(array $solution, int $ticketId, string $itemtype = 'Ticket'): void
    {
        $s = new \ITILSolution();
        $s->add([
            'itemtype'      => $itemtype,
            'items_id'      => $ticketId,
            'content'       => $solution['content'] ?? '',
            'date_creation' => $solution['date'] ?? date('Y-m-d H:i:s'),
            'users_id'      => $solution['_users_id'] ?? 0,
            'status'        => 3, // ITILValidation::ACCEPTED
            '_disablenotif' => true,
        ]);
    }

    // attachFilesFromComment() was refactored into createFollowup():
    // inline images go through attachInlineImages() (with src replacement),
    // regular/shared attachments are handled inline in createFollowup().

    private function downloadAndLink(string $url, int $ticketId, int $followupId, int $entityId, MigrationResult $result): void
    {
        $file = $result->measureStat('time_attachments_ms', fn() => $this->connector->downloadAttachment($url));
        if ($file === null) {
            $result->incStat('attachments_failed');
            return;
        }

        $tmpName = uniqid('bridge_') . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $file['filename']);
        $tmpPath = GLPI_TMP_DIR . '/' . $tmpName;

        if (file_put_contents($tmpPath, $file['content']) === false) {
            $result->incStat('attachments_failed');
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
            $result->incStat('attachments_failed');
            return;
        }
        $result->incStat('attachments_downloaded');

        // Link only to the followup — GLPI shows followup attachments in the
        // timeline inline. Linking to the Ticket as well creates a duplicate
        // in the Documents tab since the same file appears twice.
        $di = new \Document_Item();
        $di->add(['documents_id' => $docId, 'itemtype' => 'ITILFollowup', 'items_id' => $followupId, 'entities_id' => $entityId]);
        $result->incStat('documents_linked');
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

        return $filters;
    }
}

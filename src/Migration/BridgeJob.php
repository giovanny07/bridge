<?php

namespace GlpiPlugin\Bridge\Migration;

use CommonDBTM;
use CronTask;
use DBConnection;
use GlpiPlugin\Bridge\Connector\ConnectorFactory;
use GlpiPlugin\Bridge\Migration\BridgeJobConfig;
use GlpiPlugin\Bridge\Migration\JobLog;
use GlpiPlugin\Bridge\Resolver\GlpiResolver;
use Migration;

/**
 * Background migration job.
 *
 * Lifecycle: pending → running → completed | failed | cancelled
 *
 * Each cron tick processes MigrationCursor::CHUNK_PAGES API pages and
 * updates the job stats.  A stale heartbeat (> ZOMBIE_MINUTES minutes)
 * marks the job as failed so it can be retried.
 */
class BridgeJob extends CommonDBTM
{
    public static $rightname = 'config';

    public const STATUS_PENDING      = 'pending';
    public const STATUS_RUNNING      = 'running';
    public const STATUS_COMPLETED    = 'completed';
    public const STATUS_FAILED       = 'failed';
    public const STATUS_CANCELLED    = 'cancelled';
    public const STATUS_ROLLED_BACK  = 'rolled_back';

    /** @deprecated Use BridgeJobConfig::ZOMBIE_MINUTES. Kept for back-compat. */
    private const ZOMBIE_MINUTES = BridgeJobConfig::ZOMBIE_MINUTES;

    public static function getTable($classname = null): string
    {
        return 'glpi_plugin_bridge_jobs';
    }

    // ------------------------------------------------------------------ //
    // Factory
    // ------------------------------------------------------------------ //

    public static function create(
        int    $connectionId,
        string $resourceType,
        array  $options,
        int    $createdBy
    ): self {
        global $DB;

        $DB->insert(self::getTable(), [
            'connections_id' => $connectionId,
            'resource_type'  => $resourceType,
            'status'         => self::STATUS_PENDING,
            'options_json'   => json_encode($options),
            'created_by'     => $createdBy,
            'created_at'     => date('Y-m-d H:i:s'),
            'stats_json'     => json_encode([
                'created' => 0, 'failed' => 0, 'skipped' => 0,
                'scanned' => 0, 'api_pages' => 0,
            ]),
        ]);

        $job = new self();
        $job->getFromDB((int) $DB->insertId());
        return $job;
    }

    /**
     * Returns the active (pending or running) job for a connection+type, or null.
     * Used to block concurrent job creation.
     */
    public static function findActive(int $connectionId, string $resourceType): ?self
    {
        global $DB;
        foreach ($DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => [
                'connections_id' => $connectionId,
                'resource_type'  => $resourceType,
                'status'         => [self::STATUS_PENDING, self::STATUS_RUNNING],
            ],
            'ORDER' => ['created_at DESC'],
            'LIMIT' => 1,
        ]) as $row) {
            $job = new self();
            $job->fields = $row;
            return $job;
        }
        return null;
    }

    /**
     * Validates migration options and returns an array of error strings.
     * An empty array means the options are valid.
     */
    public static function validateOptions(array $options): array
    {
        $errors = [];

        $migrationMode = (string) ($options['migration_mode'] ?? 'filters');
        if (!in_array($migrationMode, ['filters', 'ids'], true)) {
            $errors[] = 'Migration mode is invalid.';
        }

        $timePeriod = (string) ($options['time_period'] ?? 'recent');
        if ($migrationMode === 'filters' && !in_array($timePeriod, ['recent', 'from_date', 'incremental', 'manual'], true)) {
            $errors[] = 'Time period is invalid.';
        }

        // Validate created_after / updated_after dates
        foreach (['created_after' => 'Created after', 'updated_after' => 'Updated after'] as $key => $label) {
            $val = trim((string) ($options[$key] ?? ''));
            if ($val === '') continue;
            $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $val);
            if ($parsed === false) {
                $errors[] = "$label: invalid date format (expected YYYY-MM-DD).";
                continue;
            }
            if ($parsed > new \DateTimeImmutable('+1 day')) {
                $errors[] = "$label: date cannot be in the future.";
            }
            if ($parsed < new \DateTimeImmutable('-20 years')) {
                $errors[] = "$label: date is more than 20 years ago — please narrow the range.";
            }
        }

        if ($migrationMode === 'filters' && $timePeriod === 'from_date' && trim((string) ($options['created_after'] ?? '')) === '') {
            $errors[] = 'Created after is required when using From date.';
        }

        if ($migrationMode === 'filters' && $timePeriod === 'incremental' && trim((string) ($options['updated_after'] ?? '')) === '') {
            $errors[] = 'Updated after is required when using Incremental.';
        }

        if ($migrationMode === 'filters' && $timePeriod === 'manual' && (int) ($options['start_page'] ?? 1) < 1) {
            $errors[] = 'Start page must be greater than or equal to 1.';
        }

        // Validate limit
        $limit = (int) ($options['limit'] ?? 50);
        if ($limit < 1 || $limit > 500) {
            $errors[] = 'Limit must be between 1 and 500.';
        }

        // Validate source_ids format when provided
        $sourceIds = trim((string) ($options['source_ids'] ?? ''));
        if ($migrationMode === 'ids' && $sourceIds === '') {
            $errors[] = 'Source IDs are required when using By source IDs.';
        }
        if ($sourceIds !== '') {
            $validIds = 0;
            foreach (array_map('trim', explode(',', $sourceIds)) as $rawId) {
                $id = ltrim($rawId, '#');
                if ($id !== '' && !ctype_digit($id)) {
                    $errors[] = "Source IDs: \"$rawId\" is not a valid ticket number or ID.";
                    break;
                }
                if ($id !== '') {
                    $validIds++;
                }
            }
            if ($migrationMode === 'ids' && $validIds === 0) {
                $errors[] = 'Source IDs must include at least one valid ticket number or ID.';
            }
        }

        return $errors;
    }

    // ------------------------------------------------------------------ //
    // Accessors
    // ------------------------------------------------------------------ //

    public function id(): int              { return (int) ($this->fields['id'] ?? 0); }
    public function connectionId(): int    { return (int) ($this->fields['connections_id'] ?? 0); }
    public function resourceType(): string { return (string) ($this->fields['resource_type'] ?? 'incidents'); }
    public function status(): string       { return (string) ($this->fields['status'] ?? self::STATUS_PENDING); }
    public function isPending(): bool      { return $this->status() === self::STATUS_PENDING; }
    public function isRunning(): bool      { return $this->status() === self::STATUS_RUNNING; }
    public function isFinished(): bool     { return in_array($this->status(), [self::STATUS_COMPLETED, self::STATUS_FAILED, self::STATUS_CANCELLED, self::STATUS_ROLLED_BACK], true); }

    public function options(): array
    {
        $v = $this->fields['options_json'] ?? '{}';
        return is_string($v) ? (json_decode($v, true) ?? []) : [];
    }

    public function stats(): array
    {
        $v = $this->fields['stats_json'] ?? '{}';
        return is_string($v) ? (json_decode($v, true) ?? []) : [];
    }

    // ------------------------------------------------------------------ //
    // State transitions
    // ------------------------------------------------------------------ //

    public function markRunning(): void
    {
        global $DB;
        $DB->update(self::getTable(), [
            'status'          => self::STATUS_RUNNING,
            'started_at'      => date('Y-m-d H:i:s'),
            'last_heartbeat'  => date('Y-m-d H:i:s'),
        ], ['id' => $this->id()]);
        $this->fields['status'] = self::STATUS_RUNNING;
    }

    public function heartbeat(array $accumulatedStats): void
    {
        global $DB;
        $DB->update(self::getTable(), [
            'last_heartbeat' => date('Y-m-d H:i:s'),
            'stats_json'     => json_encode($accumulatedStats),
        ], ['id' => $this->id()]);
        $this->fields['stats_json'] = json_encode($accumulatedStats);
    }

    public function complete(array $finalStats): void
    {
        global $DB;
        $DB->update(self::getTable(), [
            'status'      => self::STATUS_COMPLETED,
            'finished_at' => date('Y-m-d H:i:s'),
            'stats_json'  => json_encode($finalStats),
        ], ['id' => $this->id()]);
        $this->fields['status'] = self::STATUS_COMPLETED;
    }

    public function fail(string $error, array $stats = []): void
    {
        global $DB;
        $DB->update(self::getTable(), [
            'status'        => self::STATUS_FAILED,
            'finished_at'   => date('Y-m-d H:i:s'),
            'error_message' => mb_substr($error, 0, 1000),
            'stats_json'    => json_encode($stats),
        ], ['id' => $this->id()]);
        $this->fields['status'] = self::STATUS_FAILED;
    }

    public function cancel(): void
    {
        global $DB;
        $DB->update(self::getTable(), [
            'status'      => self::STATUS_CANCELLED,
            'finished_at' => date('Y-m-d H:i:s'),
        ], ['id' => $this->id()]);
        $this->fields['status'] = self::STATUS_CANCELLED;
        // Also cancel associated cursor
        MigrationCursor::cancelForConnection($this->connectionId(), $this->resourceType());
    }

    // ------------------------------------------------------------------ //
    // Cron
    // ------------------------------------------------------------------ //

    /**
     * Legacy single-slot cron action (Etapa 1 / back-compat).
     * When BridgeJobConfig::PARALLEL_JOBS is false this is the only active slot
     * and it picks any pending job regardless of resource type.
     * Once PARALLEL_JOBS is true the typed slots below take over and this slot
     * becomes a no-op so it can be safely removed in a future release.
     */
    public static function cronProcessJobs(CronTask $task): int
    {
        global $DB;
        self::recoverZombies($DB);

        if (BridgeJobConfig::PARALLEL_JOBS) {
            return 0; // typed slots are active; this slot is retired
        }

        $job = self::pickOldestPending();
        if ($job === null) {
            return 0;
        }

        if (self::hasRunningJobForType($job->connectionId(), $job->resourceType(), $job->id())) {
            $task->log('Job #' . $job->id() . ' skipped — another job for the same connection+type is already running.');
            return 0;
        }

        $task->addVolume(1);
        $task->log('Processing job #' . $job->id() . ' (' . $job->resourceType() . ')');

        return self::processJobChunk($job, $task);
    }

    // ------------------------------------------------------------------ //
    // Typed cron slots — Etapa 2 (parallel jobs per resource type)
    // ------------------------------------------------------------------ //

    /**
     * Cron slot dedicated to incidents jobs.
     * Runs as an independent OS process, parallel to changes and problems slots.
     */
    public static function cronProcessIncidents(CronTask $task): int
    {
        return self::cronForType($task, 'incidents');
    }

    /**
     * Cron slot dedicated to changes jobs.
     * Runs as an independent OS process, parallel to incidents and problems slots.
     */
    public static function cronProcessChanges(CronTask $task): int
    {
        return self::cronForType($task, 'changes');
    }

    /**
     * Cron slot dedicated to problems jobs.
     * Runs as an independent OS process, parallel to incidents and changes slots.
     */
    public static function cronProcessProblems(CronTask $task): int
    {
        return self::cronForType($task, 'problems');
    }

    /**
     * Shared logic for typed cron slots: recover zombies, pick the oldest
     * pending job for the given type, guard against overlap, then run a chunk.
     */
    private static function cronForType(CronTask $task, string $resourceType): int
    {
        global $DB;
        self::recoverZombies($DB);

        $job = self::pickOldestPending($resourceType);
        if ($job === null) {
            return 0;
        }

        if (self::hasRunningJobForType($job->connectionId(), $resourceType, $job->id())) {
            $task->log('Job #' . $job->id() . ' skipped — another ' . $resourceType . ' job is already running.');
            return 0;
        }

        $task->addVolume(1);
        $task->log('Processing job #' . $job->id() . ' (' . $resourceType . ')');

        return self::processJobChunk($job, $task);
    }

    // ------------------------------------------------------------------ //
    // Internal cron helpers (also used by typed slots in Etapa 2)
    // ------------------------------------------------------------------ //

    /**
     * Picks the oldest pending job, optionally filtered by resource type.
     * Returns null when there is nothing to process.
     */
    private static function pickOldestPending(?string $resourceType = null): ?self
    {
        global $DB;
        $where = ['status' => self::STATUS_PENDING];
        if ($resourceType !== null) {
            $where['resource_type'] = $resourceType;
        }
        foreach ($DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => $where,
            'ORDER' => ['created_at ASC'],
            'LIMIT' => 1,
        ]) as $row) {
            $job = new self();
            $job->fields = $row;
            return $job;
        }
        return null;
    }

    /**
     * Returns true when another job for the same connection + resource type
     * is currently RUNNING. Prevents two cron instances from double-processing.
     */
    private static function hasRunningJobForType(int $connectionId, string $resourceType, int $excludeJobId): bool
    {
        global $DB;
        foreach ($DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => [
                'connections_id' => $connectionId,
                'resource_type'  => $resourceType,
                'status'         => self::STATUS_RUNNING,
                'NOT'            => ['id' => $excludeJobId],
            ],
            'LIMIT' => 1,
        ]) as $_) {
            return true;
        }
        return false;
    }

    /**
     * Runs one migration chunk for the given job and updates DB state.
     * Shared by all cron slot variants (typed in Etapa 2, generic here).
     * Returns 1 so callers can forward it as the CronTask return value.
     */
    private static function processJobChunk(BridgeJob $job, CronTask $task): int
    {
        global $DB;

        $job->markRunning();
        $chunkStart = microtime(true);

        try {
            $connection = new \GlpiPlugin\Bridge\Connection();
            if (!$connection->getFromDB($job->connectionId())) {
                throw new \RuntimeException('Connection #' . $job->connectionId() . ' not found.');
            }

            $options     = $job->options();
            $optionsHash = MigrationCursor::hashOptions($options);
            $cursor      = MigrationCursor::findActive($job->connectionId(), $job->resourceType(), $optionsHash);

            $connector  = ConnectorFactory::make($connection);
            $normalizer = ConnectorFactory::makeNormalizer((string) $connection->fields['system_type']);
            $resolver   = GlpiResolver::create();

            $engine = new MigrationEngine(
                $connector,
                $normalizer,
                $resolver,
                $job->connectionId(),
                (int) ($connection->fields['entities_id'] ?? 0),
                (int) ($connection->fields['default_groups_id'] ?? 0),
                (int) ($options['default_requesters_id'] ?? 0),
                $job->resourceType(),
                max(1, min(BridgeJobConfig::PARALLEL_API_MAX, (int) ($connection->fields['parallel_api_pages'] ?? BridgeJobConfig::PARALLEL_API_PAGES))),
            );

            $engine->setJobId($job->id());
            [$result, $cursor] = $engine->run($options, $cursor);

            $durationMs = (int) round((microtime(true) - $chunkStart) * 1000);

            $chunkErrors = array_map(
                static fn(array $r) => sprintf('#%s %s: %s', $r['number'] ?? '?', mb_substr($r['name'] ?? '', 0, 40), $r['reason'] ?? ''),
                $result->failed
            );

            $chunkNumber = JobLog::chunkCount($job->id()) + 1;
            JobLog::append(
                jobId:      $job->id(),
                chunk:      $chunkNumber,
                pagesRead:  (int) ($result->stats['api_pages'] ?? 0),
                scanned:    (int) ($result->stats['scanned']   ?? 0),
                created:    count($result->created),
                failed:     count($result->failed),
                skipped:    count($result->skipped),
                durationMs: $durationMs,
                cursorPage: $cursor?->currentPage() ?? 0,
                errors:     $chunkErrors,
                metrics:    $result->stats
            );

            $prev  = $job->stats();
            $stats = $prev;
            foreach ($result->stats as $key => $value) {
                if (is_numeric($value)) {
                    $stats[$key] = (int) ($stats[$key] ?? 0) + (int) $value;
                }
            }
            $stats['created']           = (int) ($prev['created'] ?? 0) + count($result->created);
            $stats['failed']            = (int) ($prev['failed']  ?? 0) + count($result->failed);
            $stats['skipped']           = (int) ($prev['skipped'] ?? 0) + count($result->skipped);
            $stats['chunks']            = $chunkNumber;
            $stats['duration_ms_total'] = (int) ($prev['duration_ms_total'] ?? 0) + $durationMs;

            $task->log(sprintf(
                'Job #%d chunk #%d done in %dms: +%d created, %d scanned, %d failed, cursor page %d',
                $job->id(), $chunkNumber, $durationMs,
                count($result->created), (int) ($result->stats['scanned'] ?? 0),
                count($result->failed), $cursor?->currentPage() ?? 0
            ));

            $done = ($cursor === null || !$cursor->isActive());

            if (self::isCancelledInDatabase($job->id())) {
                $task->log('Job #' . $job->id() . ' was cancelled; chunk results were recorded but the job will not continue.');
                return 1;
            }

            if ($done) {
                $job->complete($stats);
                $task->log('Job #' . $job->id() . ' completed. Total: ' . $stats['created'] . ' created in ' . $chunkNumber . ' chunks.');
            } else {
                $job->heartbeat($stats);
                $DB->update(self::getTable(), ['status' => self::STATUS_PENDING], ['id' => $job->id()]);
            }

        } catch (\Throwable $e) {
            if (self::isCancelledInDatabase($job->id())) {
                $task->log('Job #' . $job->id() . ' was cancelled while handling an error: ' . $e->getMessage());
                return 1;
            }
            $job->fail($e->getMessage(), $job->stats());
            $task->log('Job #' . $job->id() . ' FAILED: ' . $e->getMessage());
        }

        return 1;
    }

    private static function isCancelledInDatabase(int $jobId): bool
    {
        $fresh = self::getById($jobId);
        return $fresh !== null && $fresh->status() === self::STATUS_CANCELLED;
    }

    private static function recoverZombies(object $DB): void
    {
        $cutoff = date('Y-m-d H:i:s', strtotime('-' . BridgeJobConfig::ZOMBIE_MINUTES . ' minutes'));

        // Running jobs with a stale heartbeat
        $DB->update(self::getTable(), [
            'status'        => self::STATUS_FAILED,
            'finished_at'   => date('Y-m-d H:i:s'),
            'error_message' => 'Job timed out (no heartbeat for ' . BridgeJobConfig::ZOMBIE_MINUTES . ' minutes).',
        ], [
            'status'         => self::STATUS_RUNNING,
            'last_heartbeat' => ['<', $cutoff],
        ]);

        // Pending jobs never picked up — cron gap or scheduler outage
        $DB->update(self::getTable(), [
            'status'        => self::STATUS_FAILED,
            'finished_at'   => date('Y-m-d H:i:s'),
            'error_message' => 'Job stuck in pending for over ' . BridgeJobConfig::ZOMBIE_MINUTES . ' minutes. Check that the GLPI cron is running.',
        ], [
            'status'     => self::STATUS_PENDING,
            'created_at' => ['<', $cutoff],
        ]);
    }

    // ------------------------------------------------------------------ //
    // Retry
    // ------------------------------------------------------------------ //

    /**
     * Creates a new pending job with the same options as this one.
     * Cancels the current job and any active cursor so the new run
     * starts fresh from the boundary page.
     */
    public function retry(int $requestedBy): self
    {
        // Cancel this job
        if (!$this->isFinished()) {
            $this->cancel();
        } else {
            // Still cancel the associated cursor so the new job re-calculates boundary
            MigrationCursor::cancelForConnection($this->connectionId(), $this->resourceType());
        }

        return self::create(
            $this->connectionId(),
            $this->resourceType(),
            $this->options(),
            $requestedBy
        );
    }

    /**
     * Purges only failed migration records for this job's connection+type,
     * so the next run will retry them without affecting successful records.
     * Returns the number of purged records.
     */
    public function retryFailedRecords(): int
    {
        return MigrationRecord::purgeFailed($this->connectionId(), $this->resourceType());
    }

    /**
     * Rollback: purges from GLPI every item created by this job (using migration records),
     * then removes those records (allowing re-migration) and marks the job rolled_back.
     *
     * @return array{purged:int, failed:int, not_found:int, total:int}
     */
    public function rollback(): array
    {
        global $DB;

        if (!$this->isFinished() || $this->status() === self::STATUS_ROLLED_BACK) {
            throw new \RuntimeException('Only finished jobs that have not been rolled back can be rolled back.');
        }

        $records  = MigrationRecord::getByJobId($this->id(), MigrationRecord::STATUS_SUCCESS);
        if ($records === []) {
            throw new \RuntimeException('This job has no successful migration records to roll back.');
        }

        $purged   = 0;
        $failed   = 0;
        $notFound = 0;

        $itemtype = match ($this->resourceType()) {
            'problems' => 'Problem',
            'changes'  => 'Change',
            default    => 'Ticket',
        };

        foreach ($records as $record) {
            $glpiId = (int) ($record['tickets_id'] ?? 0);
            if ($glpiId <= 0) {
                $notFound++;
                continue;
            }
            $item = new $itemtype();
            if (!$item->getFromDB($glpiId)) {
                $notFound++;
                continue;
            }
            try {
                // Force-purge (hard delete, not soft trash)
                if ($item->delete(['id' => $glpiId], 1)) {
                    $purged++;
                } else {
                    $failed++;
                }
            } catch (\Throwable) {
                $failed++;
            }
        }

        // Remove migration records so these items can be re-migrated
        MigrationRecord::purgeByJobId($this->id());

        // Mark job rolled_back
        $DB->update(self::getTable(), [
            'status'      => self::STATUS_ROLLED_BACK,
            'finished_at' => date('Y-m-d H:i:s'),
        ], ['id' => $this->id()]);
        $this->fields['status'] = self::STATUS_ROLLED_BACK;

        return [
            'purged'    => $purged,
            'failed'    => $failed,
            'not_found' => $notFound,
            'total'     => count($records),
        ];
    }

    // ------------------------------------------------------------------ //
    // Queries
    // ------------------------------------------------------------------ //

    /**
     * Returns a compact status snapshot for the connection list UI:
     * last job status/date and accumulated created/failed counts.
     */
    public static function getConnectionSummary(int $connectionId): array
    {
        global $DB;

        $summary = ['last_status' => null, 'last_at' => null, 'total_created' => 0, 'total_failed' => 0, 'active_job_id' => null];

        // Most recent job
        foreach ($DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => ['connections_id' => $connectionId],
            'ORDER' => ['created_at DESC'],
            'LIMIT' => 1,
        ]) as $row) {
            $stats = json_decode($row['stats_json'] ?? '{}', true) ?? [];
            $summary['last_status']    = $row['status'];
            $summary['last_at']        = $row['created_at'];
            $summary['total_created'] += (int) ($stats['created'] ?? 0);
            $summary['total_failed']  += (int) ($stats['failed']  ?? 0);
        }

        // Active job (pending/running)
        foreach ($DB->request([
            'SELECT' => ['id'],
            'FROM'   => self::getTable(),
            'WHERE'  => ['connections_id' => $connectionId, 'status' => [self::STATUS_PENDING, self::STATUS_RUNNING]],
            'ORDER'  => ['created_at DESC'],
            'LIMIT'  => 1,
        ]) as $row) {
            $summary['active_job_id'] = (int) $row['id'];
        }

        // Lifetime totals from all completed jobs
        foreach ($DB->request([
            'FROM'   => self::getTable(),
            'WHERE'  => ['connections_id' => $connectionId, 'status' => self::STATUS_COMPLETED],
        ]) as $row) {
            $stats = json_decode($row['stats_json'] ?? '{}', true) ?? [];
            $summary['total_created'] += (int) ($stats['created'] ?? 0);
            $summary['total_failed']  += (int) ($stats['failed']  ?? 0);
        }

        return $summary;
    }

    /**
     * Returns all active (pending or running) jobs for a connection, one per
     * resource type at most, ordered by resource_type for a stable display.
     * Used by the jobs list UI to show the parallel-jobs banner.
     *
     * @return array<int, array{id:int, resource_type:string, status:string}>
     */
    public static function getActiveForConnection(int $connectionId): array
    {
        global $DB;
        $rows = [];
        foreach ($DB->request([
            'SELECT' => ['id', 'resource_type', 'status'],
            'FROM'   => self::getTable(),
            'WHERE'  => [
                'connections_id' => $connectionId,
                'status'         => [self::STATUS_PENDING, self::STATUS_RUNNING],
            ],
            'ORDER'  => ['resource_type ASC', 'created_at ASC'],
        ]) as $row) {
            $rows[] = ['id' => (int) $row['id'], 'resource_type' => (string) $row['resource_type'], 'status' => (string) $row['status']];
        }
        return $rows;
    }

    /**
     * Returns the Bootstrap badge CSS classes for a given resource type.
     * Used in the jobs list and status pages for consistent colour coding.
     */
    public static function resourceTypeBadgeClass(string $type): string
    {
        return match ($type) {
            'incidents'        => 'bg-primary',
            'changes'          => 'bg-warning text-dark',
            'problems'         => 'bg-danger',
            'service_requests' => 'bg-info text-dark',
            default            => 'bg-secondary',
        };
    }

    /**
     * Returns the cron slot name registered in setup.php for a resource type.
     * Used to check the correct slot's last-run time in isCronHealthy().
     */
    public static function cronSlotName(string $resourceType): string
    {
        return match ($resourceType) {
            'incidents' => 'ProcessIncidents',
            'changes'   => 'ProcessChanges',
            'problems'  => 'ProcessProblems',
            default     => 'ProcessJobs',
        };
    }

    public static function getForConnection(int $connectionId, int $limit = 50): array
    {
        global $DB;
        $rows = [];
        foreach ($DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => ['connections_id' => $connectionId],
            'ORDER' => ['created_at DESC'],
            'LIMIT' => $limit,
        ]) as $row) {
            $rows[] = $row;
        }
        return $rows;
    }

    public static function getById(?int $id): ?self
    {
        if ($id === null || $id <= 0) {
            return null;
        }
        $job = new self();
        if (!$job->getFromDB($id)) {
            return null;
        }
        return $job;
    }

    public static function getStatusPayload(int $id, bool $includeLogs = false, bool $includeRecent = false): array
    {
        $job = self::getById($id);
        if ($job === null) {
            return ['error' => 'Job not found'];
        }
        $payload = [
            'id'             => $job->id(),
            'status'         => $job->status(),
            'resource_type'  => $job->resourceType(),
            'stats'          => $job->stats(),
            'error_message'  => (string) ($job->fields['error_message'] ?? ''),
            'created_at'     => (string) ($job->fields['created_at']    ?? ''),
            'started_at'     => (string) ($job->fields['started_at']    ?? ''),
            'finished_at'    => (string) ($job->fields['finished_at']   ?? ''),
            'last_heartbeat' => (string) ($job->fields['last_heartbeat'] ?? ''),
        ];
        if ($includeLogs) {
            $payload['logs'] = JobLog::forJob($id);
        }
        if ($includeRecent) {
            $records = MigrationRecord::getRecent($job->connectionId(), $job->resourceType(), 25);
            // Build GLPI ticket URL per record
            $glpiClass = match ($job->resourceType()) {
                'problems' => 'Problem',
                'changes'  => 'Change',
                default    => 'Ticket',
            };
            foreach ($records as &$r) {
                $r['glpi_url'] = (int) ($r['tickets_id'] ?? 0) > 0
                    ? $glpiClass::getFormURLWithID((int) $r['tickets_id'])
                    : null;
            }
            unset($r);
            $payload['recent'] = $records;
        }
        return $payload;
    }

    // ------------------------------------------------------------------ //
    // Schema
    // ------------------------------------------------------------------ //

    public static function install(Migration $migration): void
    {
        global $DB;

        $ks   = DBConnection::getDefaultPrimaryKeySignOption();
        $cs   = DBConnection::getDefaultCharset();
        $coll = DBConnection::getDefaultCollation();
        $table = self::getTable();

        if (!$DB->tableExists($table)) {
            $migration->displayMessage("Installing $table");
            $DB->doQueryOrDie(<<<SQL
            CREATE TABLE IF NOT EXISTS `$table` (
                `id`             int {$ks} NOT NULL AUTO_INCREMENT,
                `connections_id` int {$ks} NOT NULL DEFAULT 0,
                `resource_type`  varchar(64)  NOT NULL DEFAULT 'incidents',
                `status`         varchar(16)  NOT NULL DEFAULT 'pending',
                `options_json`   text,
                `stats_json`     text,
                `error_message`  text,
                `created_by`     int {$ks} NOT NULL DEFAULT 0,
                `created_at`     timestamp    NOT NULL,
                `started_at`     timestamp    DEFAULT NULL,
                `finished_at`    timestamp    DEFAULT NULL,
                `last_heartbeat` timestamp    DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `status`    (`status`),
                KEY `connection`(`connections_id`, `resource_type`, `status`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$cs} COLLATE={$coll} ROW_FORMAT=DYNAMIC;
            SQL, $DB->error());
        }
    }

    public static function uninstall(Migration $migration): void
    {
        $migration->displayMessage('Uninstalling ' . self::getTable());
        $migration->dropTable(self::getTable());
    }
}

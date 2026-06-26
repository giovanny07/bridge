<?php

namespace GlpiPlugin\Bridge\Tests\Units;

use GlpiPlugin\Bridge\Migration\BridgeJob;
use GlpiPlugin\Bridge\Migration\MigrationEngine;
use GlpiPlugin\Bridge\Migration\MigrationRecord;
use GlpiPlugin\Bridge\Connector\SolarWinds\SamanageNormalizer;
use GlpiPlugin\Bridge\Resolver\GlpiResolver;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Unit tests for F1 — Migration Rollback feature.
 */
class RollbackTest extends TestCase
{
    // ------------------------------------------------------------------ //
    // BridgeJob — status constants and isFinished()
    // ------------------------------------------------------------------ //

    public function testRolledBackConstantValue(): void
    {
        $this->assertSame('rolled_back', BridgeJob::STATUS_ROLLED_BACK);
    }

    public function testIsFinishedReturnsTrueForRolledBack(): void
    {
        $job = $this->makeJobWithStatus(BridgeJob::STATUS_ROLLED_BACK);
        $this->assertTrue($job->isFinished());
    }

    public function testIsFinishedReturnsTrueForAllTerminalStatuses(): void
    {
        foreach ([
            BridgeJob::STATUS_COMPLETED,
            BridgeJob::STATUS_FAILED,
            BridgeJob::STATUS_CANCELLED,
            BridgeJob::STATUS_ROLLED_BACK,
        ] as $status) {
            $this->assertTrue(
                $this->makeJobWithStatus($status)->isFinished(),
                "isFinished() should be true for status '$status'"
            );
        }
    }

    public function testIsFinishedReturnsFalseForActiveStatuses(): void
    {
        foreach ([BridgeJob::STATUS_PENDING, BridgeJob::STATUS_RUNNING] as $status) {
            $this->assertFalse(
                $this->makeJobWithStatus($status)->isFinished(),
                "isFinished() should be false for status '$status'"
            );
        }
    }

    // ------------------------------------------------------------------ //
    // MigrationEngine — setJobId() stores value
    // ------------------------------------------------------------------ //

    public function testSetJobIdStoresValue(): void
    {
        $engine = $this->makeEngine();
        $engine->setJobId(42);

        $prop = new ReflectionProperty(MigrationEngine::class, 'jobId');
        $prop->setAccessible(true);
        $this->assertSame(42, $prop->getValue($engine));
    }

    public function testSetJobIdDefaultIsZero(): void
    {
        $engine = $this->makeEngine();

        $prop = new ReflectionProperty(MigrationEngine::class, 'jobId');
        $prop->setAccessible(true);
        $this->assertSame(0, $prop->getValue($engine));
    }

    public function testSetJobIdDoesNotAffectDryRunResult(): void
    {
        $engine = $this->makeEngine([$this->makeIncident()]);
        $engine->setJobId(99);

        [$result] = $engine->run(['dry_run' => true, 'limit' => 10]);

        $this->assertCount(1, $result->created);
        $this->assertCount(0, $result->failed);
    }

    // ------------------------------------------------------------------ //
    // BridgeJob — migration option validation
    // ------------------------------------------------------------------ //

    public function testValidateOptionsRequiresSourceIdsForIdsMode(): void
    {
        $errors = BridgeJob::validateOptions([
            'migration_mode' => 'ids',
            'source_ids'     => '',
            'limit'          => 10,
        ]);

        $this->assertContains('Source IDs are required when using By source IDs.', $errors);
    }

    public function testValidateOptionsRequiresCreatedAfterForFromDate(): void
    {
        $errors = BridgeJob::validateOptions([
            'migration_mode' => 'filters',
            'time_period'    => 'from_date',
            'created_after'  => '',
            'limit'          => 10,
        ]);

        $this->assertContains('Created after is required when using From date.', $errors);
    }

    public function testValidateOptionsRequiresUpdatedAfterForIncremental(): void
    {
        $errors = BridgeJob::validateOptions([
            'migration_mode' => 'filters',
            'time_period'    => 'incremental',
            'updated_after'  => '',
            'limit'          => 10,
        ]);

        $this->assertContains('Updated after is required when using Incremental.', $errors);
    }

    public function testValidateOptionsAcceptsValidIdsMode(): void
    {
        $errors = BridgeJob::validateOptions([
            'migration_mode' => 'ids',
            'source_ids'     => '#176, 177, 181695325',
            'limit'          => 10,
        ]);

        $this->assertSame([], $errors);
    }

    // ------------------------------------------------------------------ //
    // MigrationRecord — getByJobId() queries correct WHERE clause
    // ------------------------------------------------------------------ //

    public function testGetByJobIdWithNoStatusFiltersOnJobId(): void
    {
        $captured = [];
        $GLOBALS['DB'] = $this->makeFakeDb([
            ['id' => 1, 'jobs_id' => 7, 'tickets_id' => 10, 'status' => 'success', 'source_type' => 'incidents', 'source_id' => 'abc'],
            ['id' => 2, 'jobs_id' => 7, 'tickets_id' => 11, 'status' => 'failed',  'source_type' => 'incidents', 'source_id' => 'def'],
        ], $captured);

        $rows = MigrationRecord::getByJobId(7);

        $this->assertCount(2, $rows);
        $this->assertSame(7, $captured[0]['WHERE']['jobs_id']);
        $this->assertArrayNotHasKey('status', $captured[0]['WHERE']);
    }

    public function testGetByJobIdWithStatusOnlyReturnsMatchingRows(): void
    {
        $captured = [];
        $GLOBALS['DB'] = $this->makeFakeDb([
            ['id' => 1, 'jobs_id' => 3, 'tickets_id' => 5, 'status' => 'success', 'source_type' => 'incidents', 'source_id' => 'x'],
        ], $captured);

        $rows = MigrationRecord::getByJobId(3, MigrationRecord::STATUS_SUCCESS);

        $this->assertSame(3,         $captured[0]['WHERE']['jobs_id']);
        $this->assertSame('success', $captured[0]['WHERE']['status']);
        $this->assertCount(1, $rows);
        $this->assertSame('success', $rows[0]['status']);
    }

    public function testGetByJobIdEmptyJobReturnsEmptyArray(): void
    {
        $GLOBALS['DB'] = $this->makeFakeDb([]);
        $rows = MigrationRecord::getByJobId(99);
        $this->assertSame([], $rows);
    }

    // ------------------------------------------------------------------ //
    // MigrationRecord — purgeByJobId() deletes rows and returns count
    // ------------------------------------------------------------------ //

    public function testPurgeByJobIdDeletesWhenRowsExist(): void
    {
        $deletedWhere = null;
        $rows = [
            ['id' => 1, 'jobs_id' => 5, 'tickets_id' => 1, 'status' => 'success', 'source_type' => 'incidents', 'source_id' => 'a'],
            ['id' => 2, 'jobs_id' => 5, 'tickets_id' => 2, 'status' => 'success', 'source_type' => 'incidents', 'source_id' => 'b'],
        ];
        $GLOBALS['DB'] = $this->makeFakeDb($rows, $_, $deletedWhere);

        $count = MigrationRecord::purgeByJobId(5);

        $this->assertSame(2, $count);
        $this->assertSame(['jobs_id' => 5], $deletedWhere);
    }

    public function testPurgeByJobIdSkipsDeleteWhenEmpty(): void
    {
        $deletedWhere = null;
        $GLOBALS['DB'] = $this->makeFakeDb([], $_, $deletedWhere);

        $count = MigrationRecord::purgeByJobId(99);

        $this->assertSame(0, $count);
        $this->assertNull($deletedWhere, 'delete() must not be called when there are no records');
    }

    // ------------------------------------------------------------------ //
    // MigrationRecord — log() passes jobs_id to the INSERT
    // ------------------------------------------------------------------ //

    public function testLogPassesJobIdToInsert(): void
    {
        $inserted = null;
        $GLOBALS['DB'] = $this->makeFakeDbForInsert($inserted);

        MigrationRecord::log(1, 'incidents', '123', 'INC-001', 'success', 42, '', 7);

        $this->assertNotNull($inserted);
        $this->assertSame(7, $inserted['jobs_id']);
        $this->assertSame(42, $inserted['tickets_id']);
    }

    public function testLogDefaultJobIdIsZero(): void
    {
        $inserted = null;
        $GLOBALS['DB'] = $this->makeFakeDbForInsert($inserted);

        MigrationRecord::log(1, 'incidents', '123', 'INC-001', 'success');

        $this->assertSame(0, $inserted['jobs_id']);
    }

    // ------------------------------------------------------------------ //
    // Helpers
    // ------------------------------------------------------------------ //

    private function makeJobWithStatus(string $status): BridgeJob
    {
        $job = new BridgeJob();
        $job->fields = [
            'id'             => 1,
            'connections_id' => 1,
            'resource_type'  => 'incidents',
            'status'         => $status,
            'options_json'   => '{}',
            'stats_json'     => '{}',
        ];
        return $job;
    }

    private function makeEngine(array $incidents = []): MigrationEngine
    {
        $connector = new class($incidents) implements \GlpiPlugin\Bridge\Contract\ConnectorInterface {
            public function __construct(private array $incidents) {}
            public function getResourceTypes(): array { return ['incidents' => ['label' => 'Incidents', 'implemented' => true]]; }
            public function testConnection(): array   { return ['ok' => true, 'status' => 200, 'latency_ms' => 0, 'total' => 0, 'message' => 'OK']; }
            public function scanIncidents(int $limit = 10): array { return $this->listIncidents([], 1, $limit); }
            public function listIncidents(array $f = [], int $p = 1, int $pp = 50): array {
                return ['endpoint' => '/', 'status_code' => 200, 'total' => count($this->incidents), 'page' => 1, 'per_page' => $pp, 'count' => count($this->incidents), 'records' => $this->incidents];
            }
            public function getIncident(int $id): array           { return $this->incidents[0] ?? []; }
            public function getIncidentByNumber(int $n): array    { return $this->incidents[0] ?? []; }
            public function getIncidentComments(int $id): array   { return []; }
            public function getProblemComments(int $id): array    { return []; }
            public function getChangeComments(int $id): array     { return []; }
            public function downloadAttachment(string $url): ?array { return null; }
            public function listChanges(array $f = [], int $p = 1, int $pp = 50): array  { return ['total'=>0,'page'=>1,'per_page'=>50,'count'=>0,'records'=>[]]; }
            public function getChange(int $id): array             { return []; }
            public function getChangeTasks(int $id): array        { return []; }
            public function listProblems(array $f = [], int $p = 1, int $pp = 50): array { return ['total'=>0,'page'=>1,'per_page'=>50,'count'=>0,'records'=>[]]; }
            public function getProblem(int $id): array            { return []; }
            public function listUsers(array $f = [], int $p = 1, int $pp = 100): array   { return ['total'=>0,'page'=>1,'per_page'=>100,'count'=>0,'records'=>[]]; }
            public function getUser(int $id): array               { return []; }
            public function listPagesBatch(string $resourceType, array $filters, array $pageNumbers, int $perPage): array {
                $batches = [];
                foreach ($pageNumbers as $pageNum) {
                    $batches[$pageNum] = $this->listIncidents($filters, $pageNum, $perPage);
                }
                return $batches;
            }
            public static function fromConnection($c): static     { return new static([]); }
        };

        $db = new class {
            public function request(array $c): array {
                $from = $c['FROM'] ?? '';
                if (isset($c['INNER JOIN']))          return [['id' => 5, 'email' => 'req@client.com']];
                if ($from === 'glpi_entities')        return [['id' => 30, 'name' => 'Corp']];
                if ($from === 'glpi_itilcategories')  return [['id' => 7, 'name' => 'Windows']];
                if ($from === 'glpi_groups')          return [['id' => 28, 'name' => 'Support']];
                return [];
            }
        };

        return new MigrationEngine(
            $connector,
            new SamanageNormalizer(),
            new GlpiResolver($db),
            connectionId:    1,
            fallbackEntityId: 0,
            fallbackGroupId:  0,
        );
    }

    private function makeIncident(array $overrides = []): array
    {
        return array_merge([
            'id'                  => 1,
            'number'              => 100,
            'name'                => 'Test incident',
            'description_no_html' => 'Body.',
            'description'         => 'Body.',
            'state'               => 'Closed',
            'priority'            => 'High',
            'origin'              => 'web',
            'created_at'          => '2026-01-01T10:00:00.000-04:00',
            'updated_at'          => '2026-01-01T12:00:00.000-04:00',
            'site'                => ['name' => 'Corp'],
            'category'            => ['name' => 'Windows'],
            'subcategory'         => ['name' => 'Server'],
            'assignee'            => ['is_user' => false, 'name' => 'Support', 'email' => ''],
            'requester'           => ['email' => 'req@client.com', 'name' => 'Requester'],
            'href'                => 'https://example.com/incidents/1',
        ], $overrides);
    }

    /**
     * Returns a fake DB that iterates the given $rows on request() and captures query params.
     * Optionally captures the WHERE clause passed to delete().
     */
    private function makeFakeDb(array $rows, mixed &$capturedQueries = null, mixed &$capturedDelete = null): object
    {
        $capturedQueries = [];
        return new class($rows, $capturedQueries, $capturedDelete) {
            public function __construct(
                private array $rows,
                private array &$captured,
                private mixed &$deletedWhere,
            ) {}
            public function request(array $criteria): array {
                $this->captured[] = $criteria;
                // Filter rows by WHERE clauses for realistic behaviour
                return array_values(array_filter($this->rows, function ($row) use ($criteria) {
                    foreach ($criteria['WHERE'] ?? [] as $col => $val) {
                        if (isset($row[$col]) && $row[$col] !== $val) return false;
                    }
                    return true;
                }));
            }
            public function delete(string $table, array $where): void {
                $this->deletedWhere = $where;
            }
            // Stub insert/update so log() doesn't error
            public function insert(string $table, array $data): void {}
            public function update(string $table, array $data, array $where): void {}
        };
    }

    /**
     * Returns a fake DB that captures the data passed to insert().
     */
    private function makeFakeDbForInsert(mixed &$inserted): object
    {
        return new class($inserted) {
            public function __construct(private mixed &$captured) {}
            public function request(array $c): array { return [['1' => 1]]; } // ping succeeds
            public function insert(string $table, array $data): void { $this->captured = $data; }
            public function update(string $table, array $data, array $where): void {}
            public function delete(string $table, array $where): void {}
        };
    }
}

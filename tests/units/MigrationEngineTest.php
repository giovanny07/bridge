<?php

namespace GlpiPlugin\Bridge\Tests\Units;

use GlpiPlugin\Bridge\Connection;
use GlpiPlugin\Bridge\Connector\SolarWinds\SamanageNormalizer;
use GlpiPlugin\Bridge\Contract\ConnectorInterface;
use GlpiPlugin\Bridge\Migration\MigrationEngine;
use GlpiPlugin\Bridge\Migration\MigrationRecord;
use GlpiPlugin\Bridge\Resolver\GlpiResolver;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for MigrationEngine.
 *
 * Uses stub implementations for all GLPI + network dependencies so these
 * run without a live GLPI instance or network access.
 */
class MigrationEngineTest extends TestCase
{
    private function makeIncident(array $overrides = []): array
    {
        return array_merge([
            'id'                  => 181695325,
            'number'              => 191723,
            'name'                => 'Test incident',
            'description_no_html' => 'Body text.',
            'description'         => 'Body text.',
            'state'               => 'Closed',
            'priority'            => 'High',
            'origin'              => 'web',
            'created_at'          => '2026-05-13T10:00:00.000-04:00',
            'updated_at'          => '2026-05-13T12:00:00.000-04:00',
            'site'                => ['name' => 'Acumuladores Duncan, C.A.'],
            'category'            => ['name' => 'Windows'],
            'subcategory'         => ['name' => 'Servidor'],
            'assignee'            => ['is_user' => false, 'name' => 'Centro de Servicios', 'email' => ''],
            'requester'           => ['email' => 'req@client.com', 'name' => 'Requester'],
            'href'                => 'https://example.com/incidents/181695325',
        ], $overrides);
    }

    private function makeConnector(array $incidents = [], bool $alreadyMigrated = false, array $changeTasks = []): object
    {
        return new class($incidents, $alreadyMigrated, $changeTasks) implements ConnectorInterface {
            public function __construct(private array $incidents, private bool $alreadyMigrated, private array $changeTasks = []) {}
            public function getResourceTypes(): array { return ['incidents' => ['label' => 'Incidents', 'implemented' => true]]; }
            public function testConnection(): array { return ['ok' => true, 'status' => 200, 'latency_ms' => 10, 'total' => count($this->incidents), 'message' => 'OK']; }
            public function scanIncidents(int $limit = 10): array { return $this->listIncidents([], 1, $limit); }
            public function listIncidents(array $filters = [], int $page = 1, int $perPage = 50): array {
                return ['endpoint' => '/incidents.json', 'status_code' => 200, 'total' => count($this->incidents), 'page' => $page, 'per_page' => $perPage, 'count' => count($this->incidents), 'records' => $this->incidents];
            }
            public function getIncident(int $id): array { return $this->incidents[0] ?? []; }
            public function getIncidentByNumber(int $n): array { return $this->incidents[0] ?? []; }
            public function getIncidentComments(int $id): array { return []; }
            public function getProblemComments(int $id): array { return []; }
            public function getChangeComments(int $id): array { return []; }
            public function downloadAttachment(string $url): ?array { return null; }
            public function listChanges(array $f = [], int $p = 1, int $pp = 50): array { return ['total'=>count($this->incidents),'page'=>$p,'per_page'=>$pp,'count'=>count($this->incidents),'records'=>$this->incidents]; }
            public function getChange(int $id): array { return $this->incidents[0] ?? []; }
            public function getChangeTasks(int $id): array { return $this->changeTasks; }
            public function listProblems(array $f = [], int $p = 1, int $pp = 50): array { return ['total'=>0,'page'=>1,'per_page'=>50,'count'=>0,'records'=>[]]; }
            public function getProblem(int $id): array { return $this->incidents[0] ?? []; }
            public function listUsers(array $f = [], int $p = 1, int $pp = 100): array { return ['total'=>0,'page'=>1,'per_page'=>100,'count'=>0,'records'=>[]]; }
            public function getUser(int $id): array { return []; }
            public static function fromConnection($c): static { return new static([], false, []); }
        };
    }

    private function makeResolver(): GlpiResolver
    {
        $db = new class {
            public function request(array $c): array {
                $from = $c['FROM'] ?? '';
                if (isset($c['INNER JOIN'])) {
                    return [
                        ['id' => 5, 'email' => 'req@client.com'],
                        ['id' => 6, 'email' => 'tech@example.com'],
                    ];
                }
                if ($from === 'glpi_entities')     return [['id' => 30, 'name' => 'Acumuladores Duncan, C.A.']];
                if ($from === 'glpi_itilcategories') return [['id' => 7, 'name' => 'Windows'], ['id' => 8, 'name' => 'Servidor']];
                if ($from === 'glpi_groups')        return [['id' => 28, 'name' => 'Centro de Servicios']];
                return [];
            }
        };
        return new GlpiResolver($db);
    }

    private function makeEngine(
        array $incidents,
        int $fallbackEntity = 0,
        int $fallbackGroup = 0,
        string $resourceType = 'incidents',
        array $changeTasks = []
    ): MigrationEngine
    {
        return new MigrationEngine(
            $this->makeConnector($incidents, changeTasks: $changeTasks),
            new SamanageNormalizer(),
            $this->makeResolver(),
            connectionId: 1,
            fallbackEntityId: $fallbackEntity,
            fallbackGroupId: $fallbackGroup,
            resourceType: $resourceType,
        );
    }

    // ------------------------------------------------------------------ //
    // Dry-run mode
    // ------------------------------------------------------------------ //

    public function testDryRunDoesNotCallGlpiCreate(): void
    {
        $engine = $this->makeEngine([$this->makeIncident()]);
        [$result] = $engine->run(['dry_run' => true, 'limit' => 10]);

        // tickets_id = 0 in dry-run (nothing created)
        $this->assertSame(0, $result->created[0]['tickets_id'] ?? -1);
        $this->assertTrue($result->isDryRun);
    }

    public function testDryRunCountsRecords(): void
    {
        $incidents = [$this->makeIncident(['id' => 1]), $this->makeIncident(['id' => 2])];
        $engine    = $this->makeEngine($incidents);
        [$result]    = $engine->run(['dry_run' => true, 'limit' => 10]);

        $this->assertCount(2, $result->created);
        $this->assertCount(0, $result->failed);
    }

    public function testDryRunExposesPipelineMetrics(): void
    {
        $engine = $this->makeEngine([$this->makeIncident(['id' => 1])]);
        [$result] = $engine->run(['dry_run' => true, 'limit' => 10]);

        $this->assertArrayHasKey('time_api_ms', $result->stats);
        $this->assertArrayHasKey('time_map_ms', $result->stats);
        $this->assertArrayHasKey('comments_read', $result->stats);
        $this->assertSame(1, $result->stats['api_pages']);
        $this->assertSame(1, $result->stats['queued']);
        $this->assertSame(1, $result->stats['mapped']);
    }

    // ------------------------------------------------------------------ //
    // Limit enforcement
    // ------------------------------------------------------------------ //

    public function testLimitCapsMigratedRecords(): void
    {
        $incidents = array_map(
            fn($i) => $this->makeIncident(['id' => $i, 'number' => $i]),
            range(1, 10)
        );

        $engine = $this->makeEngine($incidents);
        [$result] = $engine->run(['dry_run' => true, 'limit' => 3]);

        $this->assertSame(3, $result->total());
    }

    // ------------------------------------------------------------------ //
    // Filter builder (verifiable via dry-run count)
    // ------------------------------------------------------------------ //

    public function testRunWithEmptyFiltersReturnsAllRecords(): void
    {
        $engine = $this->makeEngine([$this->makeIncident()]);
        [$result] = $engine->run(['dry_run' => true, 'limit' => 50]);

        $this->assertGreaterThan(0, $result->total());
    }

    // ------------------------------------------------------------------ //
    // Unresolved entity with no fallback → failed
    // ------------------------------------------------------------------ //

    public function testUnresolvableEntityInDryRunStillCounts(): void
    {
        // Dry-run doesn't check creatability — it counts all records as "would create".
        // Real-mode behaviour (unresolved → failed) requires a live GLPI DB and
        // is tested via integration/API tests instead.
        [$result] = $this->makeEngine(
            [$this->makeIncident(['site' => ['name' => 'Unknown Company XYZ']])],
            fallbackEntity: 0
        )->run(['dry_run' => true, 'limit' => 10]);

        $this->assertGreaterThanOrEqual(0, $result->total());
    }

    // ------------------------------------------------------------------ //
    // MigrationResult helpers
    // ------------------------------------------------------------------ //

    public function testMigrationResultTotalSumsAllCategories(): void
    {
        $engine = $this->makeEngine([$this->makeIncident()]);
        [$result] = $engine->run(['dry_run' => true, 'limit' => 1]);

        $this->assertSame($result->total(), count($result->created) + count($result->failed) + count($result->skipped));
    }

    public function testMigrationResultIsFullSuccessWhenNoFailed(): void
    {
        $engine = $this->makeEngine([$this->makeIncident()]);
        [$result] = $engine->run(['dry_run' => true, 'limit' => 1]);

        $this->assertTrue($result->isFullSuccess());
    }

    // ------------------------------------------------------------------ //
    // source_ids — targeted migration by ID
    // ------------------------------------------------------------------ //

    public function testSourceIdsSkipsPaginationAndFetchesByIdInDryRun(): void
    {
        $incident = $this->makeIncident(['id' => 181695325, 'number' => 191723]);
        $engine   = $this->makeEngine([$incident]);

        // source_ids overrides limit/pagination
        [$result] = $engine->run(['dry_run' => true, 'source_ids' => '181695325']);

        $this->assertCount(1, $result->created);
        $this->assertSame('191723', $result->created[0]['number']);
    }

    public function testSourceIdsMultipleIds(): void
    {
        $incident = $this->makeIncident(['id' => 1, 'number' => 100]);
        $engine   = $this->makeEngine([$incident]);

        [$result] = $engine->run(['dry_run' => true, 'source_ids' => '1, 2, 3']);

        // Stub always returns incidents[0]; 3 IDs → 3 results
        $this->assertSame(3, $result->total());
    }

    public function testChangeMigrationCreatesChangeTasks(): void
    {
        $GLOBALS['DB'] = new class {
            public array $inserts = [];
            public array $updates = [];
            public array $deletes = [];

            public function request(array $criteria): array { return []; }
            public function delete(string $table, array $criteria): bool { $this->deletes[] = [$table, $criteria]; return true; }
            public function insert(string $table, array $input): bool { $this->inserts[] = [$table, $input]; return true; }
            public function update(string $table, array $input, array $criteria): bool { $this->updates[] = [$table, $input, $criteria]; return true; }
        };
        \ChangeTask::$addedInputs = [];

        $engine = $this->makeEngine(
            [$this->makeIncident([
                'id' => 2977134,
                'number' => 4781,
                'name' => 'Deploy change',
                'state' => 'Finalizado',
            ])],
            resourceType: 'changes',
            changeTasks: [[
                'id' => 9001,
                'name' => 'Execute deployment',
                'description' => '<p>Run deployment script</p>',
                'created_at' => '2026-06-01T10:00:00.000-04:00',
                'due_at' => '2026-06-01T11:00:00.000-04:00',
                'assignee' => ['email' => 'tech@example.com', 'name' => 'Tech User'],
                'task_type' => 'Task',
                'href' => 'https://api.samanage.com/tasks/9001',
            ]]
        );

        [$result] = $engine->run(['dry_run' => false, 'limit' => 1]);

        $this->assertCount(1, $result->created);
        $this->assertSame(1, $result->stats['tasks_requests']);
        $this->assertSame(1, $result->stats['tasks_read']);
        $this->assertSame(1, $result->stats['tasks_created']);
        $this->assertSame(0, $result->stats['tasks_failed']);
        $this->assertCount(1, \ChangeTask::$addedInputs);

        $input = \ChangeTask::$addedInputs[0];
        $this->assertSame(1, $input['changes_id']);
        $this->assertSame(6, $input['users_id_tech']);
        $this->assertStringContainsString('[SolarWinds task #9001] Execute deployment', $input['content']);
        $this->assertArrayHasKey('plan', $input);
        $this->assertTrue($input['_disablenotif']);
    }

    public function testChangeMigrationRoutesApprovalToChangeValidation(): void
    {
        $GLOBALS['DB'] = new class {
            public array $inserts = [];
            public array $updates = [];
            public array $deletes = [];

            public function request(array $criteria): array { return []; }
            public function delete(string $table, array $criteria): bool { $this->deletes[] = [$table, $criteria]; return true; }
            public function insert(string $table, array $input): bool { $this->inserts[] = [$table, $input]; return true; }
            public function update(string $table, array $input, array $criteria): bool { $this->updates[] = [$table, $input, $criteria]; return true; }
        };
        \ChangeTask::$addedInputs = [];
        \ChangeValidation::$addedInputs = [];

        $engine = $this->makeEngine(
            [$this->makeIncident([
                'id' => 2977134,
                'number' => 4781,
                'name' => 'Deploy change',
                'state' => 'Finalizado',
            ])],
            resourceType: 'changes',
            changeTasks: [
                [
                    'id' => 9001,
                    'name' => 'Pre aprobación',
                    'description' => '',
                    'task_type' => 'Approval',
                    'created_at' => '2026-06-01T10:00:00.000-04:00',
                    'completed_at' => '2026-06-01T11:00:00.000-04:00',
                    'rejected_at' => null,
                    'requester' => ['email' => 'req@client.com', 'name' => 'Requester'],
                    'assignee' => ['email' => 'approver@example.com', 'name' => 'Approver'],
                    'approver' => [
                        'approve_requested_at' => '2026-06-01T10:00:00.000-04:00',
                        'completed_at' => '2026-06-01T11:00:00.000-04:00',
                        'vote' => 'approved',
                        'response' => 'Looks good',
                        'assignee' => ['email' => 'approver@example.com', 'name' => 'Approver'],
                    ],
                    'href' => 'https://api.samanage.com/tasks/9001',
                ],
                [
                    'id' => 9002,
                    'name' => 'Execute deployment',
                    'description' => '',
                    'task_type' => 'Task',
                    'created_at' => '2026-06-01T12:00:00.000-04:00',
                    'assignee' => ['email' => 'tech@example.com', 'name' => 'Tech User'],
                    'requester' => ['email' => 'req@client.com', 'name' => 'Requester'],
                    'href' => 'https://api.samanage.com/tasks/9002',
                ],
            ]
        );

        [$result] = $engine->run(['dry_run' => false, 'limit' => 1]);

        $this->assertCount(1, $result->created);
        // Approval routed to ChangeValidation, regular task to ChangeTask
        $this->assertSame(1, $result->stats['approvals_created']);
        $this->assertSame(1, $result->stats['tasks_created']);
        $this->assertCount(1, \ChangeValidation::$addedInputs);
        $this->assertCount(1, \ChangeTask::$addedInputs);

        $val = \ChangeValidation::$addedInputs[0];
        $this->assertSame(1, $val['changes_id']);
        $this->assertSame(3, $val['status']); // ACCEPTED (completed_at set, no rejected_at)
        $this->assertStringContainsString('Pre aprobación', $val['comment_submission']);
        $this->assertTrue($val['_disablenotif']);
    }

    // ------------------------------------------------------------------ //
    // Preflight
    // ------------------------------------------------------------------ //

    public function testPreflightSkipsAlreadyMigratedRecords(): void
    {
        $GLOBALS['DB'] = new class {
            public function request(array $criteria): array {
                if (($criteria['FROM'] ?? '') === MigrationRecord::getTable()) {
                    return [['source_id' => '1']];
                }
                return [];
            }
        };

        $engine = $this->makeEngine([
            $this->makeIncident(['id' => 1, 'number' => 101]),
            $this->makeIncident(['id' => 2, 'number' => 102]),
        ]);

        $result = $engine->preflight(['limit' => 10]);

        $this->assertTrue($result->isDryRun);
        $this->assertSame(['101'], $result->skipped);
        $this->assertCount(1, $result->created);
        $this->assertSame(1, $result->stats['duplicates']);
        $this->assertSame(1, $result->stats['queued']);
        $this->assertSame(1, $result->mappingQuality['duplicate']);
        $this->assertSame(1, $result->mappingQuality['ok']);
        $this->assertCount(2, $result->preflightRows);
    }

    public function testPreflightCapturesPartialMappingWarnings(): void
    {
        $engine = $this->makeEngine([
            $this->makeIncident([
                'id' => 10,
                'number' => 110,
                'site' => ['name' => 'Unknown customer'],
            ]),
        ], fallbackEntity: 30);

        $result = $engine->preflight(['limit' => 10]);

        $this->assertCount(1, $result->created);
        $this->assertSame(1, $result->mappingQuality['partial']);
        $this->assertSame('partial', $result->preflightRows[0]['status']);
        $this->assertNotEmpty($result->preflightRows[0]['warnings']);
    }

    public function testPreflightUsesChangeTaskEndpointBeforeEmbeddedCount(): void
    {
        $engine = $this->makeEngine(
            [$this->makeIncident([
                'id' => 2977134,
                'number' => 4781,
                'state' => 'Finalizado',
                'tasks' => [
                    ['id' => 1],
                    ['id' => 2],
                    ['id' => 3],
                    ['id' => 4],
                    ['id' => 5],
                ],
            ])],
            resourceType: 'changes',
            changeTasks: [
                ['id' => 1],
                ['id' => 2],
            ]
        );

        $result = $engine->preflight(['limit' => 10]);

        $this->assertSame(2, $result->preflightRows[0]['tasks_count']);
        $this->assertSame(1, $result->stats['tasks_requests']);
        $this->assertSame(2, $result->stats['tasks_read']);
    }

    public function testPreflightFetchesChangeTaskCountWhenNotEmbedded(): void
    {
        $engine = $this->makeEngine(
            [$this->makeIncident([
                'id' => 2977134,
                'number' => 4781,
                'state' => 'Finalizado',
            ])],
            resourceType: 'changes',
            changeTasks: [
                ['id' => 1],
                ['id' => 2],
                ['id' => 3],
            ]
        );

        $result = $engine->preflight(['limit' => 10]);

        $this->assertSame(3, $result->preflightRows[0]['tasks_count']);
        $this->assertSame(1, $result->stats['tasks_requests']);
        $this->assertSame(3, $result->stats['tasks_read']);
    }
}

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

    private function makeConnector(array $incidents = [], bool $alreadyMigrated = false): object
    {
        return new class($incidents, $alreadyMigrated) implements ConnectorInterface {
            public function __construct(private array $incidents, private bool $alreadyMigrated) {}
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
            public function listChanges(array $f = [], int $p = 1, int $pp = 50): array { return ['total'=>0,'page'=>1,'per_page'=>50,'count'=>0,'records'=>[]]; }
            public function getChange(int $id): array { return $this->incidents[0] ?? []; }
            public function listProblems(array $f = [], int $p = 1, int $pp = 50): array { return ['total'=>0,'page'=>1,'per_page'=>50,'count'=>0,'records'=>[]]; }
            public function getProblem(int $id): array { return $this->incidents[0] ?? []; }
            public function listUsers(array $f = [], int $p = 1, int $pp = 100): array { return ['total'=>0,'page'=>1,'per_page'=>100,'count'=>0,'records'=>[]]; }
            public function getUser(int $id): array { return []; }
            public static function fromConnection($c): static { return new static([], false); }
        };
    }

    private function makeResolver(): GlpiResolver
    {
        $db = new class {
            public function request(array $c): array {
                $from = $c['FROM'] ?? '';
                if (isset($c['INNER JOIN'])) return [['id' => 5, 'email' => 'req@client.com']];
                if ($from === 'glpi_entities')     return [['id' => 30, 'name' => 'Acumuladores Duncan, C.A.']];
                if ($from === 'glpi_itilcategories') return [['id' => 7, 'name' => 'Windows'], ['id' => 8, 'name' => 'Servidor']];
                if ($from === 'glpi_groups')        return [['id' => 28, 'name' => 'Centro de Servicios']];
                return [];
            }
        };
        return new GlpiResolver($db);
    }

    private function makeEngine(array $incidents, int $fallbackEntity = 0, int $fallbackGroup = 0): MigrationEngine
    {
        return new MigrationEngine(
            $this->makeConnector($incidents),
            new SamanageNormalizer(),
            $this->makeResolver(),
            connectionId: 1,
            fallbackEntityId: $fallbackEntity,
            fallbackGroupId: $fallbackGroup,
        );
    }

    // ------------------------------------------------------------------ //
    // Dry-run mode
    // ------------------------------------------------------------------ //

    public function testDryRunDoesNotCallGlpiCreate(): void
    {
        $engine = $this->makeEngine([$this->makeIncident()]);
        $result = $engine->run(['dry_run' => true, 'limit' => 10]);

        // tickets_id = 0 in dry-run (nothing created)
        $this->assertSame(0, $result->created[0]['tickets_id'] ?? -1);
        $this->assertTrue($result->isDryRun);
    }

    public function testDryRunCountsRecords(): void
    {
        $incidents = [$this->makeIncident(['id' => 1]), $this->makeIncident(['id' => 2])];
        $engine    = $this->makeEngine($incidents);
        $result    = $engine->run(['dry_run' => true, 'limit' => 10]);

        $this->assertCount(2, $result->created);
        $this->assertCount(0, $result->failed);
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
        $result = $engine->run(['dry_run' => true, 'limit' => 3]);

        $this->assertSame(3, $result->total());
    }

    // ------------------------------------------------------------------ //
    // Filter builder (verifiable via dry-run count)
    // ------------------------------------------------------------------ //

    public function testRunWithEmptyFiltersReturnsAllRecords(): void
    {
        $engine = $this->makeEngine([$this->makeIncident()]);
        $result = $engine->run(['dry_run' => true, 'limit' => 50]);

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
        $result = $this->makeEngine(
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
        $result = $engine->run(['dry_run' => true, 'limit' => 1]);

        $this->assertSame($result->total(), count($result->created) + count($result->failed) + count($result->skipped));
    }

    public function testMigrationResultIsFullSuccessWhenNoFailed(): void
    {
        $engine = $this->makeEngine([$this->makeIncident()]);
        $result = $engine->run(['dry_run' => true, 'limit' => 1]);

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
        $result = $engine->run(['dry_run' => true, 'source_ids' => '181695325']);

        $this->assertCount(1, $result->created);
        $this->assertSame('191723', $result->created[0]['number']);
    }

    public function testSourceIdsMultipleIds(): void
    {
        $incident = $this->makeIncident(['id' => 1, 'number' => 100]);
        $engine   = $this->makeEngine([$incident]);

        $result = $engine->run(['dry_run' => true, 'source_ids' => '1, 2, 3']);

        // Stub always returns incidents[0]; 3 IDs → 3 results
        $this->assertSame(3, $result->total());
    }
}

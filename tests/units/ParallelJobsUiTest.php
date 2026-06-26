<?php

namespace GlpiPlugin\Bridge\Tests\Units;

use GlpiPlugin\Bridge\Migration\BridgeJob;
use PHPUnit\Framework\TestCase;

/**
 * Etapa 3 — Verifies the UI helpers added to BridgeJob for the parallel-jobs
 * observability features: coloured resource-type badges, active-job list, and
 * the cron-slot name resolver.
 */
class ParallelJobsUiTest extends TestCase
{
    // ------------------------------------------------------------------ //
    // resourceTypeBadgeClass()
    // ------------------------------------------------------------------ //

    #[\PHPUnit\Framework\Attributes\DataProvider('badgeClassProvider')]
    public function testResourceTypeBadgeClassReturnsExpectedClass(string $type, string $expected): void
    {
        $this->assertSame($expected, BridgeJob::resourceTypeBadgeClass($type));
    }

    public static function badgeClassProvider(): array
    {
        return [
            'incidents'        => ['incidents',        'bg-primary'],
            'changes'          => ['changes',           'bg-warning text-dark'],
            'problems'         => ['problems',          'bg-danger'],
            'service_requests' => ['service_requests',  'bg-info text-dark'],
            'unknown type'     => ['unknown',           'bg-secondary'],
            'empty string'     => ['',                  'bg-secondary'],
        ];
    }

    public function testResourceTypeBadgeClassReturnsString(): void
    {
        $this->assertIsString(BridgeJob::resourceTypeBadgeClass('incidents'));
    }

    // ------------------------------------------------------------------ //
    // cronSlotName()
    // ------------------------------------------------------------------ //

    #[\PHPUnit\Framework\Attributes\DataProvider('slotNameProvider')]
    public function testCronSlotNameReturnsCorrectSlot(string $type, string $expected): void
    {
        $this->assertSame($expected, BridgeJob::cronSlotName($type));
    }

    public static function slotNameProvider(): array
    {
        return [
            'incidents'    => ['incidents', 'ProcessIncidents'],
            'changes'      => ['changes',   'ProcessChanges'],
            'problems'     => ['problems',  'ProcessProblems'],
            'unknown type' => ['other',     'ProcessJobs'],
            'empty string' => ['',          'ProcessJobs'],
        ];
    }

    public function testCronSlotNamesAreDistinct(): void
    {
        $slots = [
            BridgeJob::cronSlotName('incidents'),
            BridgeJob::cronSlotName('changes'),
            BridgeJob::cronSlotName('problems'),
        ];
        $this->assertSame(count($slots), count(array_unique($slots)), 'Each resource type must map to a distinct cron slot.');
    }

    // ------------------------------------------------------------------ //
    // getActiveForConnection()
    // ------------------------------------------------------------------ //

    public function testGetActiveForConnectionMapsRowsReturnedByDb(): void
    {
        // The WHERE filter (status IN pending/running) is applied by the real DB.
        // The fake DB returns only the rows the query would match — we test that
        // getActiveForConnection() correctly maps whatever the DB gives back.
        $dbRows = [
            ['id' => 1, 'connections_id' => 5, 'resource_type' => 'incidents', 'status' => BridgeJob::STATUS_RUNNING],
            ['id' => 2, 'connections_id' => 5, 'resource_type' => 'changes',   'status' => BridgeJob::STATUS_PENDING],
        ];
        $GLOBALS['DB'] = $this->makeFakeDb($dbRows);

        $active = BridgeJob::getActiveForConnection(5);

        $this->assertCount(2, $active);
        $types = array_column($active, 'resource_type');
        $this->assertContains('incidents', $types);
        $this->assertContains('changes',   $types);
    }

    public function testGetActiveForConnectionReturnsEmptyWhenNoActiveJobs(): void
    {
        $GLOBALS['DB'] = $this->makeFakeDb([]);
        $active = BridgeJob::getActiveForConnection(99);
        $this->assertSame([], $active);
    }

    public function testGetActiveForConnectionRowShapeHasRequiredKeys(): void
    {
        $GLOBALS['DB'] = $this->makeFakeDb([
            ['id' => 7, 'connections_id' => 1, 'resource_type' => 'incidents', 'status' => BridgeJob::STATUS_RUNNING],
        ]);
        $active = BridgeJob::getActiveForConnection(1);
        $this->assertArrayHasKey('id',            $active[0]);
        $this->assertArrayHasKey('resource_type', $active[0]);
        $this->assertArrayHasKey('status',        $active[0]);
        $this->assertIsInt($active[0]['id']);
    }

    public function testGetActiveForConnectionIdIsCastToInt(): void
    {
        $GLOBALS['DB'] = $this->makeFakeDb([
            ['id' => '42', 'connections_id' => 1, 'resource_type' => 'changes', 'status' => BridgeJob::STATUS_PENDING],
        ]);
        $active = BridgeJob::getActiveForConnection(1);
        $this->assertSame(42, $active[0]['id']);
    }

    // ------------------------------------------------------------------ //
    // Helpers
    // ------------------------------------------------------------------ //

    private function makeFakeDb(array $rows): object
    {
        return new class($rows) {
            public function __construct(private array $rows) {}
            public function request(array $q): array { return $this->rows; }
            public function update(string $t, array $d, array $w): bool { return true; }
        };
    }
}

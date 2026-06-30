<?php

namespace GlpiPlugin\Bridge\Tests\Units;

use GlpiPlugin\Bridge\Migration\BridgeJobConfig;
use GlpiPlugin\Bridge\Migration\MigrationCursor;
use GlpiPlugin\Bridge\Migration\BridgeJob;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Verifies BridgeJobConfig is the single source of truth for all
 * operational constants and that dependent classes no longer define their own.
 */
class BridgeJobConfigTest extends TestCase
{
    // ------------------------------------------------------------------ //
    // Constants exist with correct types and sane ranges
    // ------------------------------------------------------------------ //

    public function testCronIntervalIsPositiveInt(): void
    {
        $this->assertIsInt(BridgeJobConfig::CRON_INTERVAL_SECONDS);
        $this->assertGreaterThan(0, BridgeJobConfig::CRON_INTERVAL_SECONDS);
    }

    public function testCronLogsLifetimeIsPositiveInt(): void
    {
        $this->assertIsInt(BridgeJobConfig::CRON_LOGS_LIFETIME_DAYS);
        $this->assertGreaterThan(0, BridgeJobConfig::CRON_LOGS_LIFETIME_DAYS);
    }

    public function testZombieMinutesIsPositiveInt(): void
    {
        $this->assertIsInt(BridgeJobConfig::ZOMBIE_MINUTES);
        $this->assertGreaterThan(0, BridgeJobConfig::ZOMBIE_MINUTES);
    }

    public function testChunkPagesIsPositiveInt(): void
    {
        $this->assertIsInt(BridgeJobConfig::CHUNK_PAGES);
        $this->assertGreaterThan(0, BridgeJobConfig::CHUNK_PAGES);
    }

    public function testPerPageIsWithinApiLimits(): void
    {
        $this->assertIsInt(BridgeJobConfig::PER_PAGE);
        $this->assertGreaterThanOrEqual(1,   BridgeJobConfig::PER_PAGE);
        $this->assertLessThanOrEqual(100, BridgeJobConfig::PER_PAGE);
    }

    // ------------------------------------------------------------------ //
    // Parallel job configuration
    // ------------------------------------------------------------------ //

    public function testParallelJobsIsBool(): void
    {
        $this->assertIsBool(BridgeJobConfig::PARALLEL_JOBS);
    }

    public function testParallelJobsActivatesTypedCronSlots(): void
    {
        // Typed slots are active and the legacy slot no-ops.
        $this->assertTrue(
            BridgeJobConfig::PARALLEL_JOBS,
            'Typed cron slots should be active. Set to false only to revert.'
        );
    }

    public function testParallelApiPagesDefaultsToSequential(): void
    {
        $this->assertSame(
            1,
            BridgeJobConfig::PARALLEL_API_PAGES,
            'PARALLEL_API_PAGES must default to 1 unless explicitly tuned.'
        );
    }

    public function testParallelApiMaxIsReasonable(): void
    {
        $this->assertIsInt(BridgeJobConfig::PARALLEL_API_MAX);
        $this->assertGreaterThanOrEqual(2, BridgeJobConfig::PARALLEL_API_MAX);
        $this->assertLessThanOrEqual(32, BridgeJobConfig::PARALLEL_API_MAX);
    }

    public function testParallelApiPagesDoesNotExceedMax(): void
    {
        $this->assertLessThanOrEqual(
            BridgeJobConfig::PARALLEL_API_MAX,
            BridgeJobConfig::PARALLEL_API_PAGES,
            'PARALLEL_API_PAGES must not exceed PARALLEL_API_MAX.'
        );
    }

    // ------------------------------------------------------------------ //
    // MigrationCursor back-compat alias resolves to the same value
    // ------------------------------------------------------------------ //

    public function testMigrationCursorChunkPagesMatchesConfig(): void
    {
        $this->assertSame(
            BridgeJobConfig::CHUNK_PAGES,
            MigrationCursor::CHUNK_PAGES,
            'MigrationCursor::CHUNK_PAGES back-compat alias must equal BridgeJobConfig::CHUNK_PAGES.'
        );
    }

    // ------------------------------------------------------------------ //
    // BridgeJob no longer defines its own ZOMBIE_MINUTES private const
    // (the private const is now an alias — this test documents the intent)
    // ------------------------------------------------------------------ //

    public function testBridgeJobDoesNotHavePublicZombieMinutes(): void
    {
        $rc = new ReflectionClass(BridgeJob::class);
        $this->assertFalse(
            $rc->hasConstant('ZOMBIE_MINUTES') && $rc->getReflectionConstant('ZOMBIE_MINUTES')->isPublic(),
            'ZOMBIE_MINUTES should not be a public constant on BridgeJob; use BridgeJobConfig.'
        );
    }

    // ------------------------------------------------------------------ //
    // MigrationEngine — PER_PAGE private const was removed (no own copy)
    // ------------------------------------------------------------------ //

    public function testMigrationEngineHasNoOwnPerPageConstant(): void
    {
        $rc = new ReflectionClass(\GlpiPlugin\Bridge\Migration\MigrationEngine::class);
        $this->assertFalse(
            $rc->hasConstant('PER_PAGE'),
            'MigrationEngine must not define its own PER_PAGE; use BridgeJobConfig::PER_PAGE.'
        );
    }

    // ------------------------------------------------------------------ //
    // Typed cron slot methods exist on BridgeJob
    // ------------------------------------------------------------------ //

    #[\PHPUnit\Framework\Attributes\DataProvider('typedCronMethodProvider')]
    public function testTypedCronMethodExists(string $method): void
    {
        $rc = new ReflectionClass(BridgeJob::class);
        $this->assertTrue(
            $rc->hasMethod($method),
            "BridgeJob must have a public static method $method() for parallel cron slots."
        );
        $rm = new ReflectionMethod(BridgeJob::class, $method);
        $this->assertTrue($rm->isPublic(),  "$method must be public.");
        $this->assertTrue($rm->isStatic(),  "$method must be static.");
    }

    public static function typedCronMethodProvider(): array
    {
        return [
            'incidents slot' => ['cronProcessIncidents'],
            'changes slot'   => ['cronProcessChanges'],
            'problems slot'  => ['cronProcessProblems'],
        ];
    }

    public function testLegacyCronJobNoOpsWhenParallelJobsEnabled(): void
    {
        if (!BridgeJobConfig::PARALLEL_JOBS) {
            $this->markTestSkipped('PARALLEL_JOBS is false — legacy slot is still active.');
        }

        // cronProcessJobs must return 0 immediately when PARALLEL_JOBS=true
        $GLOBALS['DB'] = $this->makeFakeDbNoOp();
        $task = new \CronTask();

        $result = BridgeJob::cronProcessJobs($task);

        $this->assertSame(0, $result, 'Legacy cronProcessJobs must return 0 when PARALLEL_JOBS=true.');
        $this->assertEmpty($task->logs, 'Legacy slot must not log anything when it no-ops.');
    }

    public function testTypedCronSlotReturnsZeroWhenNoPendingJobs(): void
    {
        $GLOBALS['DB'] = $this->makeEmptyDb();
        $task = new \CronTask();

        foreach (['cronProcessIncidents', 'cronProcessChanges', 'cronProcessProblems'] as $method) {
            // recoverZombies issues an update — reset before each call
            $GLOBALS['DB'] = $this->makeEmptyDb();
            $result = BridgeJob::$method($task);
            $this->assertSame(0, $result, "$method must return 0 when no pending jobs exist.");
        }
    }

    // ------------------------------------------------------------------ //
    // Helpers
    // ------------------------------------------------------------------ //

    private function makeFakeDbNoOp(): object
    {
        return new class {
            public function request(array $q): array  { return []; }
            public function update(string $t, array $d, array $w): bool { return true; }
        };
    }

    private function makeEmptyDb(): object
    {
        return new class {
            public function request(array $q): \ArrayObject { return new \ArrayObject([]); }
            public function update(string $t, array $d, array $w): bool { return true; }
        };
    }
}

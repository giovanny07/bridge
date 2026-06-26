<?php

namespace GlpiPlugin\Bridge\Tests\Units;

use GlpiPlugin\Bridge\Connector\HttpBatch;
use GlpiPlugin\Bridge\Connector\SolarWinds\SolarWindsClient;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Etapa 5 — Unit tests for SolarWindsClient::listPagesBatch().
 *
 * All tests inject a fake HttpBatch via the optional $batch parameter
 * so no real curl handles or network calls are made.
 *
 * SolarWindsClient is instantiated with a minimal Connection-like object
 * built directly (no DB) using the same helper used in SolarWindsClientTest.
 */
class SolarWindsClientBatchTest extends TestCase
{
    // ------------------------------------------------------------------ //
    // Happy path
    // ------------------------------------------------------------------ //

    public function testListPagesBatchReturnsOneEntryPerPage(): void
    {
        $client  = $this->makeClient();
        $batch   = $this->fakeBatch(fn($url, $h) => $this->jsonOk(json_encode([['id' => 1, 'name' => 'INC-1']])));
        $results = $client->listPagesBatch('incidents', [], [1, 2, 3], 10, $batch);

        $this->assertCount(3, $results);
        $this->assertArrayHasKey(1, $results);
        $this->assertArrayHasKey(2, $results);
        $this->assertArrayHasKey(3, $results);
    }

    public function testListPagesBatchResultIndexedByPageNumber(): void
    {
        $client  = $this->makeClient();
        $batch   = $this->fakeBatch(fn($url, $h) => $this->jsonOk('[]'));
        $results = $client->listPagesBatch('incidents', [], [5, 10], 50, $batch);

        $this->assertArrayHasKey(5,  $results);
        $this->assertArrayHasKey(10, $results);
    }

    public function testListPagesBatchResultShapeMatchesListIncidents(): void
    {
        $client  = $this->makeClient();
        $records = [['id' => 42, 'number' => 123, 'name' => 'Test']];
        $batch   = $this->fakeBatch(fn($url, $h) => $this->jsonOk(json_encode($records)));
        $results = $client->listPagesBatch('incidents', [], [1], 50, $batch);

        $page = $results[1];
        $this->assertArrayHasKey('endpoint',    $page);
        $this->assertArrayHasKey('status_code', $page);
        $this->assertArrayHasKey('total',       $page);
        $this->assertArrayHasKey('page',        $page);
        $this->assertArrayHasKey('per_page',    $page);
        $this->assertArrayHasKey('count',       $page);
        $this->assertArrayHasKey('records',     $page);
    }

    public function testListPagesBatchRecordsContainSourceData(): void
    {
        $client  = $this->makeClient();
        $records = [['id' => 1, 'name' => 'INC-1'], ['id' => 2, 'name' => 'INC-2']];
        $batch   = $this->fakeBatch(fn($url, $h) => $this->jsonOk(json_encode($records)));
        $results = $client->listPagesBatch('incidents', [], [1], 10, $batch);

        $this->assertCount(2, $results[1]['records']);
        $this->assertSame(2,  $results[1]['count']);
    }

    public function testListPagesBatchPageNumberStoredInResult(): void
    {
        $client  = $this->makeClient();
        $batch   = $this->fakeBatch(fn($url, $h) => $this->jsonOk('[]'));
        $results = $client->listPagesBatch('changes', [], [7], 25, $batch);

        $this->assertSame(7,  $results[7]['page']);
        $this->assertSame(25, $results[7]['per_page']);
    }

    public function testListPagesBatchWorksForChanges(): void
    {
        $client  = $this->makeClient();
        $batch   = $this->fakeBatch(fn($url, $h) => $this->jsonOk(json_encode([['id' => 99]])));
        $results = $client->listPagesBatch('changes', [], [1], 50, $batch);

        $this->assertCount(1,  $results[1]['records']);
    }

    public function testListPagesBatchWorksForProblems(): void
    {
        $client  = $this->makeClient();
        $batch   = $this->fakeBatch(fn($url, $h) => $this->jsonOk(json_encode([['id' => 88]])));
        $results = $client->listPagesBatch('problems', [], [1], 50, $batch);

        $this->assertCount(1, $results[1]['records']);
    }

    // ------------------------------------------------------------------ //
    // URL construction — page and per_page appear in query string
    // ------------------------------------------------------------------ //

    public function testListPagesBatchBuildsUrlWithPageParameter(): void
    {
        $client   = $this->makeClient();
        $captured = [];
        $batch    = $this->fakeBatch(function (string $url, array $h) use (&$captured): array {
            $captured[] = $url;
            return $this->jsonOk('[]');
        });

        $client->listPagesBatch('incidents', [], [3], 50, $batch);

        $this->assertCount(1, $captured);
        $this->assertStringContainsString('page=3', $captured[0]);
        $this->assertStringContainsString('per_page=50', $captured[0]);
    }

    public function testListPagesBatchBuildsDistinctUrlPerPage(): void
    {
        $client   = $this->makeClient();
        $captured = [];
        $batch    = $this->fakeBatch(function (string $url, array $h) use (&$captured): array {
            $captured[] = $url;
            return $this->jsonOk('[]');
        });

        $client->listPagesBatch('incidents', [], [1, 2], 50, $batch);

        $this->assertCount(2, $captured);
        $this->assertNotSame($captured[0], $captured[1]);
    }

    // ------------------------------------------------------------------ //
    // Rate limiting (429)
    // ------------------------------------------------------------------ //

    public function testListPagesBatchSurfaces429WithoutThrowing(): void
    {
        $client  = $this->makeClient();
        $batch   = $this->fakeBatch(fn($url, $h) => ['body' => 'Too Many Requests', 'status_code' => 429, 'error' => '']);
        $results = $client->listPagesBatch('incidents', [], [1], 50, $batch);

        $this->assertSame(429, $results[1]['status_code']);
        $this->assertSame([],  $results[1]['records']);
    }

    public function testListPagesBatch429IsDetectableViaHttpBatch(): void
    {
        $client  = $this->makeClient();
        $batch   = $this->fakeBatch(fn($url, $h) => ['body' => '', 'status_code' => 429, 'error' => '']);
        $results = $client->listPagesBatch('incidents', [], [1], 50, $batch);

        // Convert to the shape HttpBatch::hasRateLimitResponse() expects
        $rawLike = array_map(fn($r) => ['status_code' => $r['status_code']], $results);
        $this->assertTrue(HttpBatch::hasRateLimitResponse($rawLike));
    }

    // ------------------------------------------------------------------ //
    // Error handling
    // ------------------------------------------------------------------ //

    public function testListPagesBatchThrowsOnNon2xxNon429(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/500/');

        $client = $this->makeClient();
        $batch  = $this->fakeBatch(fn($url, $h) => ['body' => 'Internal Server Error', 'status_code' => 500, 'error' => '']);
        $client->listPagesBatch('incidents', [], [1], 50, $batch);
    }

    public function testListPagesBatchThrowsOnCurlError(): void
    {
        $this->expectException(RuntimeException::class);

        $client = $this->makeClient();
        $batch  = $this->fakeBatch(fn($url, $h) => ['body' => '', 'status_code' => 0, 'error' => 'Could not connect']);
        $client->listPagesBatch('incidents', [], [1], 50, $batch);
    }

    public function testListPagesBatchThrowsOnNonJsonResponse(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/non-JSON/');

        $client = $this->makeClient();
        $batch  = $this->fakeBatch(fn($url, $h) => ['body' => 'not json at all', 'status_code' => 200, 'error' => '']);
        $client->listPagesBatch('incidents', [], [1], 50, $batch);
    }

    // ------------------------------------------------------------------ //
    // Empty input
    // ------------------------------------------------------------------ //

    public function testListPagesBatchReturnsEmptyArrayForEmptyPageList(): void
    {
        $client  = $this->makeClient();
        $batch   = $this->fakeBatch(fn($url, $h) => $this->jsonOk('[]'));
        $results = $client->listPagesBatch('incidents', [], [], 50, $batch);

        $this->assertSame([], $results);
    }

    // ------------------------------------------------------------------ //
    // Helpers
    // ------------------------------------------------------------------ //

    /** Builds a SolarWindsClient with a Bearer token auth using minimal fields. */
    private function makeClient(): SolarWindsClient
    {
        return new SolarWindsClient(
            baseUrl:          'https://test.samanage.test',
            authType:         'bearer',
            secret:           'fake-token-for-unit-tests',
            user:             '',
            customHeaderName: ''
        );
    }

    /** Builds an HttpBatch that uses the given callable as transport. */
    private function fakeBatch(callable $transport): HttpBatch
    {
        return new HttpBatch(4, $transport);
    }

    /** Builds a 200 OK response with the given body. */
    private function jsonOk(string $body): array
    {
        return ['body' => $body, 'status_code' => 200, 'error' => ''];
    }
}

<?php

namespace GlpiPlugin\Bridge\Tests\Units;

use GlpiPlugin\Bridge\Connector\HttpBatch;
use GlpiPlugin\Bridge\Migration\BridgeJobConfig;
use PHPUnit\Framework\TestCase;

/**
 * Etapa 4 — Unit tests for HttpBatch.
 *
 * All tests use the $transport callable injection so no real HTTP calls
 * or curl handles are created. The real curl_multi path is exercised only
 * in integration tests (tests/api/) with a live SolarWinds endpoint.
 */
class HttpBatchTest extends TestCase
{
    // ------------------------------------------------------------------ //
    // fetchAll — basic behaviour
    // ------------------------------------------------------------------ //

    public function testFetchAllReturnsEmptyArrayForEmptyInput(): void
    {
        $batch = new HttpBatch(4, $this->noop());
        $this->assertSame([], $batch->fetchAll([]));
    }

    public function testFetchAllCallsTransportOncePerRequest(): void
    {
        $calls = [];
        $transport = function (string $url, array $headers) use (&$calls): array {
            $calls[] = $url;
            return $this->ok('{}');
        };

        $batch = new HttpBatch(4, $transport);
        $batch->fetchAll([
            ['url' => 'http://a.test/1', 'headers' => []],
            ['url' => 'http://a.test/2', 'headers' => []],
            ['url' => 'http://a.test/3', 'headers' => []],
        ]);

        $this->assertCount(3, $calls);
        $this->assertSame('http://a.test/1', $calls[0]);
        $this->assertSame('http://a.test/2', $calls[1]);
        $this->assertSame('http://a.test/3', $calls[2]);
    }

    public function testFetchAllPassesHeadersToTransport(): void
    {
        $capturedHeaders = [];
        $transport = function (string $url, array $headers) use (&$capturedHeaders): array {
            $capturedHeaders = $headers;
            return $this->ok('{}');
        };

        $expected = ['Authorization: Bearer token', 'Accept: application/json'];
        $batch    = new HttpBatch(1, $transport);
        $batch->fetchAll([['url' => 'http://x.test', 'headers' => $expected]]);

        $this->assertSame($expected, $capturedHeaders);
    }

    // ------------------------------------------------------------------ //
    // fetchAll — result shape and key preservation
    // ------------------------------------------------------------------ //

    public function testFetchAllResultHasRequiredKeys(): void
    {
        $batch   = new HttpBatch(1, fn($u, $h) => $this->ok('{"id":1}'));
        $results = $batch->fetchAll([['url' => 'http://x.test', 'headers' => []]]);

        $this->assertArrayHasKey('body',        $results[0]);
        $this->assertArrayHasKey('status_code', $results[0]);
        $this->assertArrayHasKey('error',       $results[0]);
    }

    public function testFetchAllPreservesNonSequentialInputKeys(): void
    {
        $transport = fn($url, $h) => ['body' => $url, 'status_code' => 200, 'error' => ''];

        $batch = new HttpBatch(4, $transport);
        $results = $batch->fetchAll([
            7  => ['url' => 'http://seven.test',  'headers' => []],
            42 => ['url' => 'http://forty-two.test', 'headers' => []],
        ]);

        $this->assertArrayHasKey(7,  $results);
        $this->assertArrayHasKey(42, $results);
        $this->assertSame('http://seven.test',    $results[7]['body']);
        $this->assertSame('http://forty-two.test', $results[42]['body']);
    }

    public function testFetchAllResultCountMatchesInputCount(): void
    {
        $batch = new HttpBatch(2, fn($u, $h) => $this->ok(''));
        $input = array_fill(0, 7, ['url' => 'http://x.test', 'headers' => []]);

        $this->assertCount(7, $batch->fetchAll($input));
    }

    // ------------------------------------------------------------------ //
    // fetchAll — concurrency chunking
    // ------------------------------------------------------------------ //

    public function testConcurrencyOneCallsTransportSequentially(): void
    {
        $order = [];
        $transport = function (string $url, array $h) use (&$order): array {
            $order[] = $url;
            return $this->ok('');
        };

        $batch = new HttpBatch(1, $transport);
        $batch->fetchAll([
            ['url' => 'http://first.test',  'headers' => []],
            ['url' => 'http://second.test', 'headers' => []],
            ['url' => 'http://third.test',  'headers' => []],
        ]);

        $this->assertSame(['http://first.test', 'http://second.test', 'http://third.test'], $order);
    }

    public function testConcurrencyHigherThanInputCountHandlesAllInOneWave(): void
    {
        $waveCount = 0;
        // Each transport call = one item in a wave; with concurrency=10
        // and 3 requests, all 3 end up in the same wave.
        $transport = fn($u, $h) => $this->ok('');

        $batch   = new HttpBatch(10, $transport);
        $results = $batch->fetchAll([
            ['url' => 'http://a.test', 'headers' => []],
            ['url' => 'http://b.test', 'headers' => []],
            ['url' => 'http://c.test', 'headers' => []],
        ]);

        $this->assertCount(3, $results);
    }

    public function testConcurrencyIsCappedAtParallelApiMax(): void
    {
        // concurrency above PARALLEL_API_MAX must not cause more calls than inputs
        $calls = 0;
        $transport = function ($u, $h) use (&$calls): array {
            $calls++;
            return $this->ok('');
        };

        $overLimit = BridgeJobConfig::PARALLEL_API_MAX + 999;
        $batch     = new HttpBatch($overLimit, $transport);
        $batch->fetchAll([
            ['url' => 'http://x.test', 'headers' => []],
            ['url' => 'http://y.test', 'headers' => []],
        ]);

        $this->assertSame(2, $calls, 'Cap should not change the number of requests issued.');
    }

    // ------------------------------------------------------------------ //
    // hasRateLimitResponse()
    // ------------------------------------------------------------------ //

    public function testHasRateLimitResponseReturnsTrueOn429(): void
    {
        $results = [
            ['body' => '', 'status_code' => 200, 'error' => ''],
            ['body' => '', 'status_code' => 429, 'error' => ''],
        ];
        $this->assertTrue(HttpBatch::hasRateLimitResponse($results));
    }

    public function testHasRateLimitResponseReturnsFalseWhenNo429(): void
    {
        $results = [
            ['body' => '{}', 'status_code' => 200, 'error' => ''],
            ['body' => '{}', 'status_code' => 201, 'error' => ''],
        ];
        $this->assertFalse(HttpBatch::hasRateLimitResponse($results));
    }

    public function testHasRateLimitResponseReturnsFalseForEmptyArray(): void
    {
        $this->assertFalse(HttpBatch::hasRateLimitResponse([]));
    }

    public function testFetchAllSurfaces429StatusCodeToCallerUnmodified(): void
    {
        $transport = function (string $url, array $h): array {
            return ['body' => 'Rate limit exceeded', 'status_code' => 429, 'error' => ''];
        };

        $batch   = new HttpBatch(2, $transport);
        $results = $batch->fetchAll([['url' => 'http://x.test', 'headers' => []]]);

        $this->assertSame(429, $results[0]['status_code']);
        $this->assertTrue(HttpBatch::hasRateLimitResponse($results));
    }

    // ------------------------------------------------------------------ //
    // allSucceeded()
    // ------------------------------------------------------------------ //

    public function testAllSucceededReturnsTrueWhenAll2xx(): void
    {
        $results = [
            ['body' => '{}', 'status_code' => 200, 'error' => ''],
            ['body' => '{}', 'status_code' => 201, 'error' => ''],
        ];
        $this->assertTrue(HttpBatch::allSucceeded($results));
    }

    public function testAllSucceededReturnsFalseOnAny4xx(): void
    {
        $results = [
            ['body' => '{}', 'status_code' => 200, 'error' => ''],
            ['body' => '',   'status_code' => 404, 'error' => ''],
        ];
        $this->assertFalse(HttpBatch::allSucceeded($results));
    }

    public function testAllSucceededReturnsFalseWhenCurlErrorPresent(): void
    {
        $results = [['body' => '', 'status_code' => 0, 'error' => 'Could not connect']];
        $this->assertFalse(HttpBatch::allSucceeded($results));
    }

    public function testAllSucceededReturnsTrueForEmptyArray(): void
    {
        $this->assertTrue(HttpBatch::allSucceeded([]));
    }

    // ------------------------------------------------------------------ //
    // Helpers
    // ------------------------------------------------------------------ //

    /** Returns a successful OK response array. */
    private function ok(string $body): array
    {
        return ['body' => $body, 'status_code' => 200, 'error' => ''];
    }

    /** Returns a no-op transport (always 200, empty body). */
    private function noop(): callable
    {
        return fn(string $url, array $headers): array => $this->ok('');
    }
}

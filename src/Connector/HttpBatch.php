<?php

namespace GlpiPlugin\Bridge\Connector;

use GlpiPlugin\Bridge\Migration\BridgeJobConfig;

/**
 * Fetches multiple HTTP requests in parallel using curl_multi_exec.
 *
 * Requests are grouped into waves of $concurrency. Within each wave all
 * requests fire simultaneously; the wave completes when the last handle
 * finishes. A 429 response is surfaced as-is — the caller decides whether
 * to retry or fall back to sequential mode via hasRateLimitResponse().
 *
 * Test-friendly: pass a $transport callable to replace curl with a stub
 * that returns predetermined responses without touching the network.
 *
 *   $batch = new HttpBatch(4, fn($url, $headers) => ['body' => '{}', 'status_code' => 200, 'error' => '']);
 */
final class HttpBatch
{
    /**
     * @param int           $concurrency  Max simultaneous requests per wave.
     *                                    1 = fully sequential (safe default from BridgeJobConfig).
     *                                    Capped internally at BridgeJobConfig::PARALLEL_API_MAX.
     * @param callable|null $transport    fn(string $url, string[] $headers): array{body:string, status_code:int, error:string}
     *                                    Null = real curl_multi. Inject a fake in tests.
     */
    public function __construct(
        private readonly int   $concurrency = BridgeJobConfig::PARALLEL_API_PAGES,
        private readonly mixed $transport   = null,   // callable|null — 'callable' not allowed as property type
    ) {}

    /**
     * Fetches all given requests, $concurrency at a time.
     * Result keys match input keys so callers can zip by index.
     *
     * @param  array<int, array{url:string, headers:string[]}> $requests
     * @return array<int, array{body:string, status_code:int, error:string}>
     */
    public function fetchAll(array $requests): array
    {
        if ($requests === []) {
            return [];
        }

        $cap     = max(1, min($this->concurrency, BridgeJobConfig::PARALLEL_API_MAX));
        $results = [];

        foreach (array_chunk($requests, $cap, true) as $wave) {
            $results += $this->transport !== null
                ? $this->executeViaTransport($wave)
                : $this->executeViaCurlMulti($wave);
        }

        return $results;
    }

    /**
     * Returns true when any result in the array has a 429 status code.
     * Callers use this to decide whether to pause before retrying.
     *
     * @param array<int, array{status_code:int}> $results
     */
    public static function hasRateLimitResponse(array $results): bool
    {
        foreach ($results as $r) {
            if (($r['status_code'] ?? 0) === 429) {
                return true;
            }
        }
        return false;
    }

    /**
     * Returns true when every result has a 2xx status code and no curl error.
     *
     * @param array<int, array{status_code:int, error:string}> $results
     */
    public static function allSucceeded(array $results): bool
    {
        foreach ($results as $r) {
            $code = (int) ($r['status_code'] ?? 0);
            if ($code < 200 || $code >= 300 || ($r['error'] ?? '') !== '') {
                return false;
            }
        }
        return true;
    }

    // ------------------------------------------------------------------ //
    // Internal: real curl_multi path
    // ------------------------------------------------------------------ //

    /**
     * @param  array<int, array{url:string, headers:string[]}> $wave
     * @return array<int, array{body:string, status_code:int, error:string}>
     */
    private function executeViaCurlMulti(array $wave): array
    {
        $multi   = curl_multi_init();
        $handles = [];

        foreach ($wave as $key => $req) {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL            => $req['url'],
                CURLOPT_HTTPHEADER     => $req['headers'] ?? [],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT        => 30,
                CURLOPT_SSL_VERIFYPEER => true,
            ]);
            curl_multi_add_handle($multi, $ch);
            $handles[$key] = $ch;
        }

        $running = 0;
        do {
            $status = curl_multi_exec($multi, $running);
            if ($running > 0) {
                curl_multi_select($multi, 0.1);
            }
        } while ($running > 0 && $status === CURLM_OK);

        $results = [];
        foreach ($handles as $key => $ch) {
            $results[$key] = [
                'body'        => (string) curl_multi_getcontent($ch),
                'status_code' => (int) (curl_getinfo($ch, CURLINFO_HTTP_CODE)),
                'error'       => curl_error($ch),
            ];
            curl_multi_remove_handle($multi, $ch);
            curl_close($ch);
        }

        curl_multi_close($multi);

        return $results;
    }

    // ------------------------------------------------------------------ //
    // Internal: test transport path
    // ------------------------------------------------------------------ //

    /**
     * @param  array<int, array{url:string, headers:string[]}> $wave
     * @return array<int, array{body:string, status_code:int, error:string}>
     */
    private function executeViaTransport(array $wave): array
    {
        $results = [];
        foreach ($wave as $key => $req) {
            $results[$key] = ($this->transport)($req['url'], $req['headers'] ?? []);
        }
        return $results;
    }
}

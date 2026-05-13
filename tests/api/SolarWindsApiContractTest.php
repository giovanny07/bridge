<?php

/**
 * SolarWinds / Samanage API contract tests.
 *
 * These tests document the behavior of the real API as discovered on
 * 2026-05-13 against servicios.daycohost.com. They are integration tests
 * that require live credentials and network access, so they are excluded
 * from the default unit test run.
 *
 * Run with:
 *   BRIDGE_API_URL=https://servicios.daycohost.com \
 *   BRIDGE_API_TOKEN='<plain_token>' \
 *   ./vendor/bin/phpunit --bootstrap tests/bootstrap.php --group api tests/api/
 *
 * The token value is the PLAIN (decrypted) bearer token, not the GLPI-encrypted one.
 *
 * -------------------------------------------------------------------------
 * FINDINGS SUMMARY (servicios.daycohost.com, 2026-05-13)
 * -------------------------------------------------------------------------
 * Auth      : X-Samanage-Authorization: Bearer <token>  ← required
 *             Authorization: Bearer <token>              ← returns 401
 * Accept    : application/vnd.samanage.v2.1+json
 * Pagination: ?per_page=N&page=N, total in X-Total-Count response header
 *
 * Available endpoints (200):
 *   /incidents    187 579 records
 *   /changes        4 518 records
 *   /users          1 550 records
 *   /problems          82 records
 *   /groups           377 records
 *   /sites            363 records
 *   /departments       15 records
 *   /catalog_items     12 records
 *
 * Not available (404): /hardware /software /assets /cmdb_items /service_requests
 *
 * Incident states   : En Proceso | Closed | Pendiente Acción Cliente
 *                     Pending Assignment | Solucionado
 * Incident priorities: High | Medium | Low
 * Incident origins  : api | external | web
 * -------------------------------------------------------------------------
 */

namespace GlpiPlugin\Bridge\Tests\Api;

use GlpiPlugin\Bridge\Connection;
use GlpiPlugin\Bridge\Connector\SolarWindsClient;
use GlpiPlugin\Bridge\Normalizer\SamanageNormalizer;
use PHPUnit\Framework\TestCase;

#[\PHPUnit\Framework\Attributes\Group('api')]
class SolarWindsApiContractTest extends TestCase
{
    private static SolarWindsClient $client;
    private static string           $baseUrl;
    private static string           $token;

    public static function setUpBeforeClass(): void
    {
        self::$token   = (string) getenv('BRIDGE_API_TOKEN');
        self::$baseUrl = rtrim((string) getenv('BRIDGE_API_URL'), '/');
    }

    private function requireCredentials(): void
    {
        if (self::$token === '' || self::$baseUrl === '') {
            $this->markTestSkipped(
                'Set BRIDGE_API_URL and BRIDGE_API_TOKEN environment variables to run API contract tests.'
            );
        }
    }

    private function client(): SolarWindsClient
    {
        return new SolarWindsClient(self::$baseUrl, Connection::AUTH_BEARER, self::$token);
    }

    private function curl(string $endpoint, array $extraHeaders = []): array
    {
        $headers = array_merge([
            'X-Samanage-Authorization: Bearer ' . self::$token,
            'Accept: application/vnd.samanage.v2.1+json',
        ], $extraHeaders);

        $responseHeaders = [];
        $ch = curl_init(self::$baseUrl . $endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => false,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HEADERFUNCTION => function ($c, string $h) use (&$responseHeaders): int {
                $pos = strpos($h, ':');
                if ($pos !== false) {
                    $responseHeaders[strtolower(trim(substr($h, 0, $pos)))] = trim(substr($h, $pos + 1));
                }
                return strlen($h);
            },
        ]);

        $body   = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        return [
            'status'  => $status,
            'headers' => $responseHeaders,
            'body'    => (string) $body,
            'json'    => json_decode((string) $body, true),
        ];
    }

    // ================================================================== //
    // Authentication
    // ================================================================== //

    /**
     * The Samanage API requires X-Samanage-Authorization, not Authorization.
     * This is the most critical contract: wrong header → 401, right header → 200.
     */
    public function testCorrectAuthHeaderReturns200(): void
    {
        $this->requireCredentials();

        $res = $this->curl('/incidents.json?per_page=1');

        $this->assertSame(200, $res['status'],
            'X-Samanage-Authorization: Bearer must return 200.');
    }

    public function testStandardAuthorizationHeaderReturns401(): void
    {
        $this->requireCredentials();

        $ch = curl_init(self::$baseUrl . '/incidents.json?per_page=1');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . self::$token,
                'Accept: application/json',
            ],
            CURLOPT_TIMEOUT => 15,
        ]);
        $status = (int) curl_getinfo(curl_exec($ch) !== false ? $ch : $ch, CURLINFO_RESPONSE_CODE);
        curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        $this->assertSame(401, $status,
            'Standard Authorization: Bearer must be rejected (401).');
    }

    // ================================================================== //
    // Available endpoints
    // ================================================================== //

    // NOTE: Samanage requires the .json extension on all endpoints.
    //       Omitting it returns 406 (Not Acceptable) regardless of Accept header.

    #[\PHPUnit\Framework\Attributes\DataProvider('availableEndpointProvider')]
    public function testKnownEndpointReturns200(string $endpoint): void
    {
        $this->requireCredentials();

        $res = $this->curl($endpoint . '.json?per_page=1');

        $this->assertSame(200, $res['status'], "$endpoint must be available.");
    }

    public static function availableEndpointProvider(): array
    {
        return [
            ['/incidents'],
            ['/changes'],
            ['/users'],
            ['/problems'],
            ['/groups'],
            ['/sites'],
            ['/departments'],
            ['/catalog_items'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unavailableEndpointProvider')]
    public function testUnknownEndpointReturns404(string $endpoint): void
    {
        $this->requireCredentials();

        $res = $this->curl($endpoint . '.json?per_page=1');

        $this->assertSame(404, $res['status'], "$endpoint must not be available.");
    }

    public static function unavailableEndpointProvider(): array
    {
        return [
            ['/hardware'],
            ['/software'],
            ['/assets'],
            ['/cmdb_items'],
            ['/service_requests'],
        ];
    }

    // ================================================================== //
    // Pagination
    // ================================================================== //

    public function testIncidentsResponseIncludesTotalCountHeader(): void
    {
        $this->requireCredentials();

        $res = $this->curl('/incidents.json?per_page=1');

        $this->assertArrayHasKey('x-total-count', $res['headers'],
            'X-Total-Count header must be present.');
        $this->assertGreaterThan(0, (int) $res['headers']['x-total-count'],
            'X-Total-Count must be a positive integer.');
    }

    public function testPerPageLimitsReturnedRecords(): void
    {
        $this->requireCredentials();

        $res = $this->curl('/incidents.json?per_page=3');

        $this->assertIsArray($res['json']);
        $this->assertCount(3, $res['json'], 'per_page=3 must return exactly 3 records.');
    }

    public function testPage2ReturnsDifferentRecordsThanPage1(): void
    {
        $this->requireCredentials();

        $page1 = $this->curl('/incidents.json?per_page=2&page=1')['json'];
        $page2 = $this->curl('/incidents.json?per_page=2&page=2')['json'];

        $this->assertIsArray($page1);
        $this->assertIsArray($page2);
        $this->assertNotEmpty($page1);
        $this->assertNotEmpty($page2);
        $this->assertNotSame($page1[0]['id'], $page2[0]['id'],
            'Page 1 and page 2 must return different records.');
    }

    // ================================================================== //
    // Incident structure
    // ================================================================== //

    public function testIncidentHasRequiredFields(): void
    {
        $this->requireCredentials();

        $res = $this->curl('/incidents.json?per_page=1');
        $incident = $res['json'][0] ?? null;

        $this->assertNotNull($incident, 'Must have at least one incident.');

        $required = ['id', 'number', 'name', 'state', 'priority', 'origin',
                     'created_at', 'updated_at', 'requester', 'description_no_html'];

        foreach ($required as $field) {
            $this->assertArrayHasKey($field, $incident, "Incident must have field '$field'.");
        }
    }

    public function testIncidentStateIsOneOfKnownValues(): void
    {
        $this->requireCredentials();

        $res    = $this->curl('/incidents.json?per_page=50');
        $states = array_unique(array_column($res['json'], 'state'));

        $known = [
            'Pending Assignment', 'En Proceso', 'Pendiente Acción Cliente',
            'Solucionado', 'Closed',
            // English variants for other instances
            'New', 'Assigned', 'Waiting for Customer', 'Resolved', 'Open',
        ];

        foreach ($states as $state) {
            $this->assertContains($state, $known,
                "Unexpected state '$state' — add it to SamanageNormalizer::STATE_MAP.");
        }
    }

    public function testIncidentPriorityIsOneOfKnownValues(): void
    {
        $this->requireCredentials();

        $res        = $this->curl('/incidents.json?per_page=50');
        $priorities = array_unique(array_column($res['json'], 'priority'));
        $known      = ['Low', 'Medium', 'High', 'Critical'];

        foreach ($priorities as $p) {
            $this->assertContains($p, $known,
                "Unexpected priority '$p' — add it to SamanageNormalizer::PRIORITY_MAP.");
        }
    }

    public function testIncidentOriginIsOneOfKnownValues(): void
    {
        $this->requireCredentials();

        $res     = $this->curl('/incidents.json?per_page=50');
        $origins = array_unique(array_column($res['json'], 'origin'));
        $known   = ['api', 'external', 'web', 'email', 'phone', 'chat'];

        foreach ($origins as $o) {
            $this->assertContains($o, $known,
                "Unexpected origin '$o' — add it to SamanageNormalizer::ORIGIN_MAP.");
        }
    }

    public function testIncidentDateIsIso8601WithOffset(): void
    {
        $this->requireCredentials();

        $res      = $this->curl('/incidents.json?per_page=1');
        $incident = $res['json'][0];

        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d+[+-]\d{2}:\d{2}$/',
            $incident['created_at'],
            'created_at must be ISO 8601 with timezone offset.'
        );
    }

    // ================================================================== //
    // Filters
    // ================================================================== //

    public function testCreatedAfterFilterReturnsValidResponse(): void
    {
        $this->requireCredentials();

        // created_after accepts YYYY-MM-DD. The API returns 200 regardless of
        // whether the date reduces the result set — it does NOT guarantee 0
        // records for a future date (server-side behaviour observed 2026-05-13).
        $future = date('Y-m-d', strtotime('+10 years'));
        $res    = $this->curl("/incidents.json?per_page=5&created_after=$future");

        $this->assertSame(200, $res['status'],
            'created_after filter must not cause an error response.');
        $this->assertIsArray($res['json'],
            'created_after filter must return a JSON array.');
    }

    public function testUpdatedAfterFilterWorks(): void
    {
        $this->requireCredentials();

        $yesterday = date('Y-m-d', strtotime('-1 day'));
        $res       = $this->curl("/incidents.json?per_page=5&updated_after=$yesterday");

        $this->assertSame(200, $res['status']);
        $this->assertIsArray($res['json'],
            'updated_after filter must return a JSON array.');
    }

    public function testSortByCreatedAtDescWorks(): void
    {
        $this->requireCredentials();

        $res  = $this->curl('/incidents.json?per_page=5&sort_by=created_at&sort_order=desc');
        $data = $res['json'];

        $this->assertIsArray($data);
        $this->assertNotEmpty($data);

        // Verify descending order
        for ($i = 0; $i < count($data) - 1; $i++) {
            $this->assertGreaterThanOrEqual(
                strtotime($data[$i + 1]['created_at']),
                strtotime($data[$i]['created_at']),
                'Records must be in descending created_at order.'
            );
        }
    }

    // ================================================================== //
    // SolarWindsClient integration (via the actual class)
    // ================================================================== //

    public function testScanIncidentsReturnsExpectedShape(): void
    {
        $this->requireCredentials();

        $result = $this->client()->scanIncidents(5);

        $this->assertArrayHasKey('endpoint',    $result);
        $this->assertArrayHasKey('status_code', $result);
        $this->assertArrayHasKey('total',       $result);
        $this->assertArrayHasKey('count',       $result);
        $this->assertArrayHasKey('records',     $result);

        $this->assertSame(200, $result['status_code']);
        $this->assertSame(5,   $result['count']);
        $this->assertGreaterThan(0, $result['total'],
            'total must reflect X-Total-Count from the server.');
    }

    public function testScanIncidentsRecordsAreNormalizable(): void
    {
        $this->requireCredentials();

        $result = $this->client()->scanIncidents(3);

        foreach ($result['records'] as $incident) {
            $ticket = SamanageNormalizer::incidentToTicket($incident);

            $this->assertNotEmpty($ticket['name'],       'Ticket name must not be empty.');
            $this->assertIsInt($ticket['status'],        'status must be int.');
            $this->assertIsInt($ticket['priority'],      'priority must be int.');
            $this->assertIsInt($ticket['requesttypes_id'], 'requesttypes_id must be int.');
            $this->assertNotNull($ticket['date'],        'date must be parseable.');
        }
    }

    // ================================================================== //
    // testConnection()
    // ================================================================== //

    public function testTestConnectionReturnsOkTrue(): void
    {
        $this->requireCredentials();

        $result = $this->client()->testConnection();

        $this->assertTrue($result['ok'], 'testConnection() must return ok:true with valid credentials.');
        $this->assertSame(200, $result['status']);
        $this->assertGreaterThan(0, $result['latency_ms'], 'latency_ms must be measured.');
        $this->assertGreaterThan(0, $result['total'],      'total must reflect X-Total-Count.');
    }

    public function testTestConnectionReturnsFalseWithBadToken(): void
    {
        $this->requireCredentials();

        $client = new SolarWindsClient(self::$baseUrl, Connection::AUTH_BEARER, 'bad-token');
        $result = $client->testConnection();

        $this->assertFalse($result['ok'],  'testConnection() must return ok:false for invalid token.');
        $this->assertSame(401, $result['status']);
        $this->assertNotEmpty($result['message']);
    }

    // ================================================================== //
    // Sub-resources
    // ================================================================== //

    public function testSingleIncidentHasCustomFieldsValues(): void
    {
        $this->requireCredentials();

        $res      = $this->curl('/incidents.json?per_page=1');
        $id       = $res['json'][0]['id'] ?? null;

        $this->assertNotNull($id);

        $detail = $this->curl("/incidents/$id.json");

        $this->assertArrayHasKey('custom_fields_values', $detail['json'],
            'Incident detail must include custom_fields_values.');
    }
}

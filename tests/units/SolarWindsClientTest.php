<?php

namespace GlpiPlugin\Bridge\Tests\Units;

use GlpiPlugin\Bridge\Connection;
use GlpiPlugin\Bridge\Connector\SolarWinds\SolarWindsClient;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class SolarWindsClientTest extends TestCase
{
    private function makeClient(
        string $baseUrl,
        string $authType  = Connection::AUTH_BEARER,
        string $secret    = 'tok',
        string $user      = '',
        string $header    = ''
    ): SolarWindsClient {
        return new SolarWindsClient($baseUrl, $authType, $secret, $user, $header);
    }

    private function callPrivate(SolarWindsClient $client, string $method, mixed ...$args): mixed
    {
        $ref = new ReflectionClass($client);
        $m   = $ref->getMethod($method);
        $m->setAccessible(true);
        return $m->invoke($client, ...$args);
    }

    // ------------------------------------------------------------------ //
    // buildUrl
    // ------------------------------------------------------------------ //

    public function testBuildUrlStripsTrailingSlashFromBase(): void
    {
        $client = $this->makeClient('https://example.com/');
        $url    = $this->callPrivate($client, 'buildUrl', '/incidents.json', []);

        $this->assertSame('https://example.com/incidents.json', $url);
    }

    public function testBuildUrlAppendsQueryString(): void
    {
        $client = $this->makeClient('https://example.com');
        $url    = $this->callPrivate($client, 'buildUrl', '/incidents.json', ['per_page' => 10, 'page' => 1]);

        $this->assertStringContainsString('per_page=10', $url);
        $this->assertStringContainsString('page=1', $url);
    }

    public function testBuildUrlWithNoQueryHasNoQuestionMark(): void
    {
        $client = $this->makeClient('https://example.com');
        $url    = $this->callPrivate($client, 'buildUrl', '/ping', []);

        $this->assertStringNotContainsString('?', $url);
    }

    // ------------------------------------------------------------------ //
    // buildAuthHeaders — Bearer
    // ------------------------------------------------------------------ //

    public function testBearerAuthUsesSamanageHeader(): void
    {
        $client  = $this->makeClient('https://example.com', Connection::AUTH_BEARER, 'mytoken');
        $headers = $this->callPrivate($client, 'buildAuthHeaders');

        // Samanage requires X-Samanage-Authorization, NOT the standard Authorization header
        $this->assertContains('X-Samanage-Authorization: Bearer mytoken', $headers);
        $this->assertNotContains('Authorization: Bearer mytoken', $headers);
    }

    public function testBearerAuthWithPrefixDoesNotDoublePrefix(): void
    {
        $client  = $this->makeClient('https://example.com', Connection::AUTH_BEARER, 'Bearer mytoken');
        $headers = $this->callPrivate($client, 'buildAuthHeaders');

        $this->assertContains('X-Samanage-Authorization: Bearer mytoken', $headers);
        $this->assertNotContains('X-Samanage-Authorization: Bearer Bearer mytoken', $headers);
    }

    // ------------------------------------------------------------------ //
    // buildAuthHeaders — Basic
    // ------------------------------------------------------------------ //

    public function testBasicAuthEncodesCredentials(): void
    {
        $client  = $this->makeClient('https://example.com', Connection::AUTH_BASIC, 'pass', 'user');
        $headers = $this->callPrivate($client, 'buildAuthHeaders');

        // Basic also uses X-Samanage-Authorization on this API
        $expected = 'X-Samanage-Authorization: Basic ' . base64_encode('user:pass');
        $this->assertContains($expected, $headers);
        // Must NOT use the plain 'Authorization: Basic ...' (no Samanage prefix)
        $this->assertNotContains('Authorization: Basic ' . base64_encode('user:pass'), $headers);
    }

    public function testBasicAuthThrowsWhenUserMissing(): void
    {
        $this->expectException(\RuntimeException::class);

        $client = $this->makeClient('https://example.com', Connection::AUTH_BASIC, 'pass', '');
        $this->callPrivate($client, 'buildAuthHeaders');
    }

    public function testBasicAuthThrowsWhenSecretMissing(): void
    {
        $this->expectException(\RuntimeException::class);

        $client = $this->makeClient('https://example.com', Connection::AUTH_BASIC, '', 'user');
        $this->callPrivate($client, 'buildAuthHeaders');
    }

    // ------------------------------------------------------------------ //
    // buildAuthHeaders — Custom header
    // ------------------------------------------------------------------ //

    public function testCustomHeaderAuthUsesProvidedHeaderName(): void
    {
        $client  = $this->makeClient('https://example.com', Connection::AUTH_CUSTOM_HEADER, 'tok', '', 'X-Api-Key');
        $headers = $this->callPrivate($client, 'buildAuthHeaders');

        $this->assertContains('X-Api-Key: tok', $headers);
    }

    public function testCustomHeaderAuthThrowsWhenHeaderNameMissing(): void
    {
        $this->expectException(\RuntimeException::class);

        $client = $this->makeClient('https://example.com', Connection::AUTH_CUSTOM_HEADER, 'tok', '', '');
        $this->callPrivate($client, 'buildAuthHeaders');
    }

    // ------------------------------------------------------------------ //
    // extractRecords
    // ------------------------------------------------------------------ //

    public function testExtractRecordsFromListResponse(): void
    {
        $client  = $this->makeClient('https://example.com');
        $records = $this->callPrivate($client, 'extractRecords', [['id' => 1], ['id' => 2]], 'incidents');

        $this->assertCount(2, $records);
    }

    public function testExtractRecordsFromNamedKey(): void
    {
        $client  = $this->makeClient('https://example.com');
        $records = $this->callPrivate($client, 'extractRecords', ['incidents' => [['id' => 1]]], 'incidents');

        $this->assertCount(1, $records);
    }

    public function testExtractRecordsFromDataKey(): void
    {
        $client  = $this->makeClient('https://example.com');
        $records = $this->callPrivate($client, 'extractRecords', ['data' => [['id' => 1], ['id' => 2]]], 'incidents');

        $this->assertCount(2, $records);
    }

    public function testExtractRecordsReturnsEmptyForUnknownShape(): void
    {
        $client  = $this->makeClient('https://example.com');
        $records = $this->callPrivate($client, 'extractRecords', ['meta' => 'something'], 'incidents');

        $this->assertSame([], $records);
    }

    // ------------------------------------------------------------------ //
    // fromConnection — type guard
    // ------------------------------------------------------------------ //

    public function testFromConnectionThrowsForNonSolarWindsType(): void
    {
        $this->expectException(\RuntimeException::class);

        $conn = new Connection();
        $conn->fields = ['system_type' => 'other', 'base_url' => 'https://x.com', 'auth_type' => 'bearer', 'auth_secret' => '', 'auth_user' => '', 'custom_header_name' => ''];

        SolarWindsClient::fromConnection($conn);
    }

    // ------------------------------------------------------------------ //
    // getResourceTypes
    // ------------------------------------------------------------------ //

    public function testGetResourceTypesReturnsArray(): void
    {
        $client = $this->makeClient('https://example.com');
        $types  = $client->getResourceTypes();

        $this->assertIsArray($types);
        $this->assertNotEmpty($types);
    }

    public function testGetResourceTypesHasIncidents(): void
    {
        $client = $this->makeClient('https://example.com');
        $types  = $client->getResourceTypes();

        $this->assertArrayHasKey('incidents', $types);
        $this->assertTrue($types['incidents']['implemented'], 'incidents must be implemented');
    }

    public function testGetResourceTypesHasLabelForEachEntry(): void
    {
        $client = $this->makeClient('https://example.com');
        foreach ($client->getResourceTypes() as $key => $meta) {
            $this->assertArrayHasKey('label',       $meta, "$key must have label");
            $this->assertArrayHasKey('implemented', $meta, "$key must have implemented flag");
        }
    }

    public function testUnimplementedTypesArePresent(): void
    {
        $client = $this->makeClient('https://example.com');
        $types  = $client->getResourceTypes();

        $unimplemented = array_filter($types, fn($m) => !$m['implemented']);
        $this->assertNotEmpty($unimplemented, 'Should have at least one future resource type listed');
    }


    // ------------------------------------------------------------------ //
    // request — missing secret guard
    // ------------------------------------------------------------------ //

    public function testRequestThrowsWhenBearerSecretIsEmpty(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/authentication secret/i');

        $client = $this->makeClient('https://example.com', Connection::AUTH_BEARER, '');
        $this->callPrivate($client, 'request', '/incidents.json', []);
    }

    // ------------------------------------------------------------------ //
    // testConnection — never throws, returns ok:false on error
    // ------------------------------------------------------------------ //

    public function testTestConnectionReturnsFalseWhenSecretIsEmpty(): void
    {
        $client = $this->makeClient('https://example.com', Connection::AUTH_BEARER, '');
        $result = $client->testConnection();

        $this->assertFalse($result['ok']);
        $this->assertArrayHasKey('message',    $result);
        $this->assertArrayHasKey('latency_ms', $result);
        $this->assertArrayHasKey('status',     $result);
        $this->assertArrayHasKey('total',      $result);
        $this->assertIsString($result['message']);
        $this->assertIsInt($result['latency_ms']);
    }

    public function testTestConnectionResultShapeIsAlwaysConsistent(): void
    {
        // With an invalid URL, testConnection must still return a well-formed array
        $client = $this->makeClient('https://0.0.0.0', Connection::AUTH_BEARER, '');
        $result = $client->testConnection();

        $this->assertIsBool($result['ok']);
        $this->assertIsInt($result['status']);
        $this->assertIsInt($result['latency_ms']);
        $this->assertIsInt($result['total']);
        $this->assertIsString($result['message']);
    }
}

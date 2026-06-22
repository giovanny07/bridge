<?php

namespace GlpiPlugin\Bridge\Tests\Units;

use GlpiPlugin\Bridge\Connection;
use PHPUnit\Framework\TestCase;

class ConnectionTest extends TestCase
{
    // ------------------------------------------------------------------ //
    // Constants
    // ------------------------------------------------------------------ //

    public function testSystemTypeConstant(): void
    {
        $this->assertSame('solarwinds', Connection::TYPE_SOLARWINDS);
    }

    public function testAuthTypeConstants(): void
    {
        $this->assertSame('bearer',        Connection::AUTH_BEARER);
        $this->assertSame('basic',         Connection::AUTH_BASIC);
        $this->assertSame('custom_header', Connection::AUTH_CUSTOM_HEADER);
    }

    // ------------------------------------------------------------------ //
    // getSupportedSystems
    // ------------------------------------------------------------------ //

    public function testSupportedSystemsContainsSolarWinds(): void
    {
        $systems = Connection::getSupportedSystems();

        $this->assertArrayHasKey(Connection::TYPE_SOLARWINDS, $systems);
        $this->assertIsString($systems[Connection::TYPE_SOLARWINDS]);
        $this->assertNotEmpty($systems[Connection::TYPE_SOLARWINDS]);
    }

    // ------------------------------------------------------------------ //
    // getAuthTypes
    // ------------------------------------------------------------------ //

    public function testAuthTypesContainsAllThreeTypes(): void
    {
        $types = Connection::getAuthTypes();

        $this->assertArrayHasKey(Connection::AUTH_BEARER,        $types);
        $this->assertArrayHasKey(Connection::AUTH_BASIC,         $types);
        $this->assertArrayHasKey(Connection::AUTH_CUSTOM_HEADER, $types);
    }

    // ------------------------------------------------------------------ //
    // getTable
    // ------------------------------------------------------------------ //

    public function testGetTableReturnsExpectedName(): void
    {
        $this->assertSame('glpi_plugin_bridge_connections', Connection::getTable());
    }

    // ------------------------------------------------------------------ //
    // URL helpers
    // ------------------------------------------------------------------ //

    public function testGetConfigURLPointsToStandaloneConfigPage(): void
    {
        $url = Connection::getConfigURL(0, false);

        $this->assertStringContainsString('/plugins/bridge/front/config.php', $url);
    }

    public function testGetConfigURLWithIdContainsConnectionId(): void
    {
        $url = Connection::getConfigURL(42, false);

        $this->assertStringContainsString('bridge_connection_id=42', $url);
    }

    public function testGetConfigURLWithoutIdOmitsConnectionId(): void
    {
        $url = Connection::getConfigURL(0, false);

        $this->assertStringNotContainsString('bridge_connection_id', $url);
    }

    public function testGetConfigFormURLPointsToFrontDir(): void
    {
        $url = Connection::getConfigFormURL(false);

        $this->assertStringContainsString('/front/config.form.php', $url);
    }

    public function testGetScanURLPointsToFrontDir(): void
    {
        $url = Connection::getScanURL(false);

        $this->assertStringContainsString('/front/scan.php', $url);
    }

    // ------------------------------------------------------------------ //
    // getDecryptedSecret
    // ------------------------------------------------------------------ //

    public function testGetDecryptedSecretReturnsEmptyWhenNoSecret(): void
    {
        $conn = new Connection();
        $conn->fields['auth_secret'] = '';

        $this->assertSame('', $conn->getDecryptedSecret());
    }

    public function testGetDecryptedSecretDecryptsStoredValue(): void
    {
        $conn = new Connection();
        $conn->fields['auth_secret'] = base64_encode('my-secret-token');

        $this->assertSame('my-secret-token', $conn->getDecryptedSecret());
    }

    // ------------------------------------------------------------------ //
    // Rights
    // ------------------------------------------------------------------ //

    public function testRightnameIsConfig(): void
    {
        $this->assertSame('config', Connection::$rightname);
    }

    public function testCanViewDelegatesToConfig(): void
    {
        $this->assertTrue(Connection::canView());
    }
}

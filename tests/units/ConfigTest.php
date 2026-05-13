<?php

namespace GlpiPlugin\Bridge\Tests\Units;

use GlpiPlugin\Bridge\Config;
use PHPUnit\Framework\TestCase;

class ConfigTest extends TestCase
{
    public function testRightnameIsConfig(): void
    {
        $this->assertSame('config', Config::$rightname);
    }

    public function testGetTypeNameReturnsNonEmptyString(): void
    {
        $this->assertNotEmpty(Config::getTypeName());
    }

    public function testCanViewReturnsBool(): void
    {
        $this->assertIsBool(Config::canView());
    }

    public function testCanUpdateReturnsBool(): void
    {
        $this->assertIsBool(Config::canUpdate());
    }

    public function testGetTabNameForItemReturnsStringForConfigType(): void
    {
        $config = new Config();
        $item   = new \Config();

        $result = $config->getTabNameForItem($item);

        $this->assertIsString($result);
    }

    public function testGetTabNameForItemReturnsEmptyForOtherType(): void
    {
        $config = new Config();

        $other = new class extends \CommonGLPI {
            public function getType(): string { return 'SomethingElse'; }
        };

        $this->assertSame('', $config->getTabNameForItem($other));
    }
}

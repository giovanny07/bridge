<?php

namespace GlpiPlugin\Bridge\Tests\Units;

use GlpiPlugin\Bridge\Profile;
use PHPUnit\Framework\TestCase;

class ProfileTest extends TestCase
{
    public function testRightsConstants(): void
    {
        $this->assertSame('plugin_bridge_config', Profile::RIGHT_CONFIG);
        $this->assertSame('plugin_bridge_migration', Profile::RIGHT_MIGRATION);
    }

    public function testGetAllRightsReturnsBridgeRights(): void
    {
        $rights = Profile::getAllRights(true);
        $fields = array_column($rights, 'field');

        $this->assertContains(Profile::RIGHT_CONFIG, $fields);
        $this->assertContains(Profile::RIGHT_MIGRATION, $fields);
    }

    public function testConfigRightAllowsReadAndUpdate(): void
    {
        $rights = Profile::getAllRights(true);
        $config = $rights[array_search(Profile::RIGHT_CONFIG, array_column($rights, 'field'), true)];

        $this->assertArrayHasKey(READ, $config['rights']);
        $this->assertArrayHasKey(UPDATE, $config['rights']);
    }

    public function testMigrationRightAllowsReadCreateUpdateAndPurge(): void
    {
        $rights = Profile::getAllRights(true);
        $migration = $rights[array_search(Profile::RIGHT_MIGRATION, array_column($rights, 'field'), true)];

        $this->assertArrayHasKey(READ, $migration['rights']);
        $this->assertArrayHasKey(CREATE, $migration['rights']);
        $this->assertArrayHasKey(UPDATE, $migration['rights']);
        $this->assertArrayHasKey(PURGE, $migration['rights']);
    }

    public function testCanHelpersReturnBool(): void
    {
        $this->assertIsBool(Profile::canConfigure());
        $this->assertIsBool(Profile::canMigrate());
    }

    public function testCheckHelpersDoNotThrowWhenAllowed(): void
    {
        Profile::checkConfigure();
        Profile::checkMigrate(READ);

        $this->addToAssertionCount(1);
    }
}

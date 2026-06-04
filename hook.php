<?php

use GlpiPlugin\Bridge\Connection;
use GlpiPlugin\Bridge\Migration\MigrationCursor;
use GlpiPlugin\Bridge\Migration\MigrationRecord;

function plugin_bridge_install(): bool
{
    $version   = plugin_version_bridge();
    $migration = new Migration($version['version']);

    Connection::install($migration);
    MigrationRecord::install($migration);
    MigrationCursor::install($migration);

    return true;
}

function plugin_bridge_uninstall(): bool
{
    $version   = plugin_version_bridge();
    $migration = new Migration($version['version']);

    Connection::uninstall($migration);
    MigrationRecord::uninstall($migration);
    MigrationCursor::uninstall($migration);

    return true;
}

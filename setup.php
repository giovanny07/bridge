<?php

use Glpi\Plugin\Hooks;
use GlpiPlugin\Bridge\Config;
use GlpiPlugin\Bridge\Connection;

define('PLUGIN_BRIDGE_VERSION', '0.5.0');
define('PLUGIN_BRIDGE_MIN_GLPI', '11.0.0');
define('PLUGIN_BRIDGE_MAX_GLPI', '11.0.99');

function plugin_init_bridge(): void
{
    global $PLUGIN_HOOKS;

    Plugin::registerClass(Connection::class);
    Plugin::registerClass(Config::class, ['addtabon' => \Config::class]);

    $PLUGIN_HOOKS['config_page']['bridge'] = 'front/config.form.php';
    $PLUGIN_HOOKS[Hooks::CSRF_COMPLIANT]['bridge'] = true;
}

function plugin_version_bridge(): array
{
    return [
        'name'         => 'Bridge',
        'version'      => PLUGIN_BRIDGE_VERSION,
        'author'       => 'Imagunet',
        'license'      => 'GPLv3+',
        'homepage'     => 'https://github.com/giovanny07/bridge',
        'requirements' => [
            'glpi' => [
                'min' => PLUGIN_BRIDGE_MIN_GLPI,
                'max' => PLUGIN_BRIDGE_MAX_GLPI,
            ],
            'php' => [
                'min' => '8.1',
            ],
        ],
    ];
}

function plugin_bridge_check_prerequisites(): bool
{
    return true;
}

function plugin_bridge_check_config(bool $verbose = false): bool
{
    return true;
}

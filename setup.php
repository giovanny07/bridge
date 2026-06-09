<?php

use Glpi\Plugin\Hooks;
use GlpiPlugin\Bridge\Config;
use GlpiPlugin\Bridge\Connection;
use GlpiPlugin\Bridge\Migration\BridgeJob;

define('PLUGIN_BRIDGE_VERSION', '1.1.1');
define('PLUGIN_BRIDGE_MIN_GLPI', '11.0.0');
define('PLUGIN_BRIDGE_MAX_GLPI', '11.0.99');

function plugin_init_bridge(): void
{
    global $PLUGIN_HOOKS;

    Plugin::registerClass(Connection::class);
    Plugin::registerClass(Config::class, ['addtabon' => \Config::class]);
    Plugin::registerClass(BridgeJob::class);

    // Register background job processor (runs every 60 seconds).
    // The itemtype must be the full class name so GLPI 11 (PSR-4) can
    // locate the static method BridgeJob::cronProcessJobs().
    // MODE_EXTERNAL: runs only via OS cron/CLI, NOT inside web requests.
    // MODE_INTERNAL blocks GLPI since each chunk makes ~40 external API calls
    // (~12 s) while holding a PHP worker thread.
    CronTask::register(BridgeJob::class, 'ProcessJobs', 60, [
        'state'          => CronTask::STATE_WAITING,
        'mode'           => CronTask::MODE_EXTERNAL,
        'logs_lifetime'  => 7,
        'comment'        => 'Process pending Bridge migration jobs (external mode — run via OS cron)',
    ]);

    $PLUGIN_HOOKS['config_page']['bridge'] = 'front/config.form.php';
    $PLUGIN_HOOKS[Hooks::ADD_CSS]['bridge']        = ['css/bridge.css'];
    $PLUGIN_HOOKS[Hooks::ADD_JAVASCRIPT]['bridge'] = ['js/bridge.js'];
    $PLUGIN_HOOKS[Hooks::CSRF_COMPLIANT]['bridge'] = true;
}

function plugin_version_bridge(): array
{
    return [
        'name'         => 'Bridge',
        'version'      => PLUGIN_BRIDGE_VERSION,
        'author'       => 'Imagunet',
        'license'      => 'GPLv3+',
        'homepage'     => 'https://github.com/Imagunet-S-A-S/bridge',
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

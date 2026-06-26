<?php

use Glpi\Plugin\Hooks;
use GlpiPlugin\Bridge\Config;
use GlpiPlugin\Bridge\Connection;
use GlpiPlugin\Bridge\Migration\BridgeJob;
use GlpiPlugin\Bridge\Migration\BridgeJobConfig;

define('PLUGIN_BRIDGE_VERSION', '1.4.7');
define('PLUGIN_BRIDGE_MIN_GLPI', '11.0.0');
define('PLUGIN_BRIDGE_MAX_GLPI', '11.0.99');

function plugin_init_bridge(): void
{
    global $PLUGIN_HOOKS;

    Plugin::registerClass(Connection::class);
    Plugin::registerClass(Config::class, ['addtabon' => \Config::class]);
    Plugin::registerClass(BridgeJob::class);

    // ── Legacy single-slot (kept for back-compat; no-ops when PARALLEL_JOBS=true) ──
    CronTask::register(BridgeJob::class, 'ProcessJobs', BridgeJobConfig::CRON_INTERVAL_SECONDS, [
        'state'         => CronTask::STATE_WAITING,
        'mode'          => CronTask::MODE_EXTERNAL,
        'logs_lifetime' => BridgeJobConfig::CRON_LOGS_LIFETIME_DAYS,
        'comment'       => 'Process pending Bridge migration jobs (legacy single-slot)',
    ]);

    // ── Typed parallel slots (Etapa 2) — each handles one resource type ──
    // Running as separate OS cron processes gives true parallelism:
    // incidents, changes, and problems migrate simultaneously.
    CronTask::register(BridgeJob::class, 'ProcessIncidents', BridgeJobConfig::CRON_INTERVAL_SECONDS, [
        'state'         => CronTask::STATE_WAITING,
        'mode'          => CronTask::MODE_EXTERNAL,
        'logs_lifetime' => BridgeJobConfig::CRON_LOGS_LIFETIME_DAYS,
        'comment'       => 'Process pending incident migration jobs (parallel slot)',
    ]);
    CronTask::register(BridgeJob::class, 'ProcessChanges', BridgeJobConfig::CRON_INTERVAL_SECONDS, [
        'state'         => CronTask::STATE_WAITING,
        'mode'          => CronTask::MODE_EXTERNAL,
        'logs_lifetime' => BridgeJobConfig::CRON_LOGS_LIFETIME_DAYS,
        'comment'       => 'Process pending change migration jobs (parallel slot)',
    ]);
    CronTask::register(BridgeJob::class, 'ProcessProblems', BridgeJobConfig::CRON_INTERVAL_SECONDS, [
        'state'         => CronTask::STATE_WAITING,
        'mode'          => CronTask::MODE_EXTERNAL,
        'logs_lifetime' => BridgeJobConfig::CRON_LOGS_LIFETIME_DAYS,
        'comment'       => 'Process pending problem migration jobs (parallel slot)',
    ]);

    $PLUGIN_HOOKS['config_page']['bridge'] = 'front/config.php';
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

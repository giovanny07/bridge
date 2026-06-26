<?php

namespace GlpiPlugin\Bridge;

use CommonDBTM;
use Config;
use DBConnection;
use GLPIKey;
use GlpiPlugin\Bridge\Migration\BridgeJobConfig;
use Html;
use Migration;
use Session;

class Connection extends CommonDBTM
{
    public static $rightname = 'config';

    public const TYPE_SOLARWINDS = 'solarwinds';

    public const AUTH_BEARER = 'bearer';
    public const AUTH_BASIC = 'basic';
    public const AUTH_CUSTOM_HEADER = 'custom_header';

    public static function getTypeName($nb = 0): string
    {
        return __('Bridge connection', 'bridge');
    }

    public static function canCreate(): bool
    {
        return Config::canUpdate();
    }

    public static function canView(): bool
    {
        return Config::canUpdate();
    }

    public static function canUpdate(): bool
    {
        return Config::canUpdate();
    }

    public static function canDelete(): bool
    {
        return Config::canUpdate();
    }

    public static function canPurge(): bool
    {
        return Config::canUpdate();
    }

    public static function getConfigURL(int $selectedId = 0, bool $full = true): string
    {
        // Standalone connections page (not the core Config tab): a normal page
        // request, so ?bridge_connection_id=N reaches ConfigPage::show() and the
        // edit form loads. $full is kept for signature compatibility — the base
        // URL is always absolute.
        $url = self::getPluginBaseURL() . '/front/config.php';

        if ($selectedId > 0) {
            $url .= '?bridge_connection_id=' . $selectedId;
        }

        return $url;
    }

    /**
     * Returns the plugin's absolute web base URL.
     *
     * Plugin::getWebDir() is deprecated in GLPI 11 and uses root_doc (a relative
     * path) instead of url_base (the full URL).  This method replicates the same
     * marketplace-vs-plugins detection but always produces a full absolute URL
     * using $CFG_GLPI['url_base'], which is safe for use as an href in any context.
     */
    public static function getPluginBaseURL(): string
    {
        global $CFG_GLPI;

        $isMarketplace = false;
        try {
            $marketplaceDir = realpath(GLPI_MARKETPLACE_DIR);
            foreach (\Plugin::getPluginDirectories() as $dir) {
                if (is_dir("$dir/bridge")) {
                    $isMarketplace = ($marketplaceDir !== false && realpath($dir) === $marketplaceDir);
                    break;
                }
            }
        } catch (\Throwable) {
            // keep default: plugins
        }

        $segment = $isMarketplace ? 'marketplace' : 'plugins';
        return rtrim($CFG_GLPI['url_base'] ?? '', '/') . '/' . $segment . '/bridge';
    }

    public static function getConfigFormURL(): string
    {
        return self::getPluginBaseURL() . '/front/config.form.php';
    }

    public static function getScanURL(): string
    {
        return self::getPluginBaseURL() . '/front/scan.php';
    }

    public static function getSupportedSystems(): array
    {
        return [
            self::TYPE_SOLARWINDS => 'SolarWinds Service Desk',
        ];
    }

    public static function getAuthTypes(): array
    {
        return [
            self::AUTH_BEARER        => 'Bearer token',
            self::AUTH_BASIC         => 'User and password',
            self::AUTH_CUSTOM_HEADER => 'Custom header',
        ];
    }

    public function addFromForm(array $input): int
    {
        $id = (int) $this->add($this->sanitizeFormInput($input));
        if ($id > 0) {
            Session::addMessageAfterRedirect(__('Connection created.', 'bridge'), true, INFO);
        }
        return $id;
    }

    public function updateFromForm(array $input): bool
    {
        $result = $this->update($this->sanitizeFormInput($input, true));
        if ($result) {
            Session::addMessageAfterRedirect(__('Connection updated.', 'bridge'), true, INFO);
        }
        return $result;
    }

    /**
     * Cascade-delete all plugin data associated with this connection when it
     * is purged.  Without this, records in migrations/cursors/jobs/logs tables
     * become orphaned and pollute the database.
     */
    /**
     * Cascade-delete all plugin data associated with this connection when it
     * is purged.  Without this, records in migrations/cursors/jobs/logs tables
     * become orphaned and pollute the database.
     */
    public function post_purgeItem(): void
    {
        global $DB;

        $id = (int) $this->fields['id'];

        try {
            // Collect job IDs before deleting jobs (logs reference them)
            $jobIds = [];
            if ($DB->tableExists('glpi_plugin_bridge_jobs')) {
                foreach ($DB->request(['SELECT' => ['id'], 'FROM' => 'glpi_plugin_bridge_jobs', 'WHERE' => ['connections_id' => $id]]) as $row) {
                    $jobIds[] = (int) $row['id'];
                }
            }

            // Delete operational logs for those jobs
            if (!empty($jobIds) && $DB->tableExists('glpi_plugin_bridge_job_logs')) {
                $DB->delete('glpi_plugin_bridge_job_logs', ['jobs_id' => $jobIds]);
            }

            // Delete remaining related tables (skip if not yet created)
            foreach ([
                'glpi_plugin_bridge_jobs'        => 'connections_id',
                'glpi_plugin_bridge_cursors'     => 'connections_id',
                'glpi_plugin_bridge_migrations'  => 'connections_id',
            ] as $table => $fk) {
                if ($DB->tableExists($table)) {
                    $DB->delete($table, [$fk => $id]);
                }
            }
        } catch (\Throwable) {
            // Silently ignore cleanup errors — the connection itself is already deleted
        }
    }

    public function prepareInputForAdd($input): array|bool
    {
        $input['date_creation'] = $_SESSION['glpi_currenttime'];
        $input['date_mod'] = $_SESSION['glpi_currenttime'];
        return $this->prepareSensitiveInput($input);
    }

    public function prepareInputForUpdate($input): array|bool
    {
        $input['date_mod'] = $_SESSION['glpi_currenttime'];
        return $this->prepareSensitiveInput($input);
    }

    public function getDecryptedSecret(): string
    {
        $secret = (string) ($this->fields['auth_secret'] ?? '');
        if ($secret === '') {
            return '';
        }
        return (string) (new GLPIKey())->decrypt($secret);
    }

    public static function install(Migration $migration): void
    {
        global $DB;

        $default_charset = DBConnection::getDefaultCharset();
        $default_collation = DBConnection::getDefaultCollation();
        $default_key_sign = DBConnection::getDefaultPrimaryKeySignOption();
        $table = self::getTable();

        if (!$DB->tableExists($table)) {
            $migration->displayMessage("Installing $table");

            $query = <<<SQL
            CREATE TABLE IF NOT EXISTS `$table` (
                `id` int {$default_key_sign} NOT NULL AUTO_INCREMENT,
                `name` varchar(255) NOT NULL,
                `system_type` varchar(64) NOT NULL DEFAULT 'solarwinds',
                `base_url` varchar(512) NOT NULL,
                `auth_type` varchar(64) NOT NULL DEFAULT 'bearer',
                `auth_user` varchar(255) DEFAULT NULL,
                `auth_secret` text,
                `custom_header_name` varchar(255) DEFAULT NULL,
                `entities_id` int {$default_key_sign} NOT NULL DEFAULT 0,
                `default_groups_id` int {$default_key_sign} NOT NULL DEFAULT 0,
                `parallel_api_pages` tinyint unsigned NOT NULL DEFAULT 1,
                `is_active` tinyint NOT NULL DEFAULT 1,
                `comment` text,
                `date_creation` timestamp NULL DEFAULT NULL,
                `date_mod` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `name` (`name`),
                KEY `system_type` (`system_type`),
                KEY `entities_id` (`entities_id`),
                KEY `default_groups_id` (`default_groups_id`),
                KEY `is_active` (`is_active`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC;
            SQL;

            $DB->doQueryOrDie($query, $DB->error());
        }

        // Upgrade: add default_groups_id if missing (existing installations)
        if (!$DB->fieldExists($table, 'default_groups_id')) {
            $migration->displayMessage("Upgrading $table: adding default_groups_id");
            $migration->addField($table, 'default_groups_id', "int {$default_key_sign} NOT NULL DEFAULT 0");
            $migration->addKey($table, 'default_groups_id');
            $migration->executeMigration();
        }

        // Upgrade: add parallel_api_pages if missing (existing installations)
        if (!$DB->fieldExists($table, 'parallel_api_pages')) {
            $migration->displayMessage("Upgrading $table: adding parallel_api_pages");
            $migration->addField($table, 'parallel_api_pages', 'tinyint unsigned NOT NULL DEFAULT 1', ['after' => 'default_groups_id']);
            $migration->executeMigration();
        }
    }

    public static function uninstall(Migration $migration): void
    {
        $migration->displayMessage('Uninstalling ' . self::getTable());
        $migration->dropTable(self::getTable());
    }

    private function sanitizeFormInput(array $input, bool $isUpdate = false): array
    {
        $clean = [
            'name'               => trim((string) ($input['name'] ?? '')),
            'system_type'        => (string) ($input['system_type'] ?? self::TYPE_SOLARWINDS),
            'base_url'           => rtrim(trim((string) ($input['base_url'] ?? '')), '/'),
            'auth_type'          => (string) ($input['auth_type'] ?? self::AUTH_BEARER),
            'auth_user'          => trim((string) ($input['auth_user'] ?? '')),
            'custom_header_name' => trim((string) ($input['custom_header_name'] ?? '')),
            'entities_id'        => (int) ($input['entities_id'] ?? 0),
            'default_groups_id'  => (int) ($input['default_groups_id'] ?? 0),
            'parallel_api_pages' => max(1, min(BridgeJobConfig::PARALLEL_API_MAX, (int) ($input['parallel_api_pages'] ?? BridgeJobConfig::PARALLEL_API_PAGES))),
            'is_active'          => isset($input['is_active']) ? 1 : 0,
            'comment'            => trim((string) ($input['comment'] ?? '')),
        ];

        if ($isUpdate) {
            $clean['id'] = (int) ($input['id'] ?? 0);
        }

        $secret = trim((string) ($input['auth_secret_plain'] ?? ''));
        if ($secret !== '') {
            $clean['auth_secret_plain'] = $secret;
        }

        return $clean;
    }

    private function prepareSensitiveInput(array $input): array|bool
    {
        if (($input['name'] ?? '') === '' || ($input['base_url'] ?? '') === '') {
            Session::addMessageAfterRedirect(__('Name and base URL are required.', 'bridge'), false, ERROR);
            return false;
        }

        if (!array_key_exists($input['system_type'], self::getSupportedSystems())) {
            Session::addMessageAfterRedirect(__('Unsupported source system.', 'bridge'), false, ERROR);
            return false;
        }

        if (!array_key_exists($input['auth_type'], self::getAuthTypes())) {
            Session::addMessageAfterRedirect(__('Unsupported authentication type.', 'bridge'), false, ERROR);
            return false;
        }

        if (!empty($input['auth_secret_plain'])) {
            $input['auth_secret'] = (new GLPIKey())->encrypt($input['auth_secret_plain']);
        }
        unset($input['auth_secret_plain']);

        return $input;
    }
}

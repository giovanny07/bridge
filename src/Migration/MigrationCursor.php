<?php

namespace GlpiPlugin\Bridge\Migration;

use CommonDBTM;
use DBConnection;
use GlpiPlugin\Bridge\Profile;
use Migration;
use GlpiPlugin\Bridge\Migration\BridgeJobConfig;

/**
 * Persistent cursor for resumable migrations.
 *
 * A cursor tracks the API page position and accumulated totals so that
 * each run can start exactly where the previous one stopped, without
 * re-scanning already-processed pages or duplicating records.
 *
 * One cursor exists per (connections_id, resource_type, options_hash).
 * The options_hash is built from the filters that affect which records
 * are fetched (state, created_after, updated_after) — not from limit or
 * content options, which can change between runs.
 */
class MigrationCursor extends CommonDBTM
{
    public static $rightname = Profile::RIGHT_MIGRATION;

    public const STATUS_ACTIVE    = 'active';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';

    /** @deprecated Use BridgeJobConfig::CHUNK_PAGES. Kept for back-compat. */
    public const CHUNK_PAGES = BridgeJobConfig::CHUNK_PAGES;

    public static function getTable($classname = null): string
    {
        return 'glpi_plugin_bridge_cursors';
    }

    // ------------------------------------------------------------------ //
    // Factory / lookup
    // ------------------------------------------------------------------ //

    /**
     * Builds the options hash from the filter-relevant keys.
     * Limit and content options are excluded — they can vary between runs.
     */
    public static function hashOptions(array $options): string
    {
        $relevant = [
            'state'         => $options['state']         ?? '',
            'created_after' => $options['created_after'] ?? '',
            'updated_after' => $options['updated_after'] ?? '',
        ];
        ksort($relevant);
        return md5(serialize($relevant));
    }

    /**
     * Finds an active cursor for the given connection + resource + filters.
     * Returns null when no cursor exists or when all filters changed.
     */
    public static function findActive(int $connectionId, string $resourceType, string $optionsHash): ?self
    {
        global $DB;

        foreach ($DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => [
                'connections_id' => $connectionId,
                'resource_type'  => $resourceType,
                'options_hash'   => $optionsHash,
                'status'         => self::STATUS_ACTIVE,
            ],
            'LIMIT' => 1,
        ]) as $row) {
            $cursor = new self();
            $cursor->fields = $row;
            return $cursor;
        }

        return null;
    }

    /**
     * Creates a new cursor and persists it.
     */
    public static function create(
        int    $connectionId,
        string $resourceType,
        string $optionsHash,
        int    $startPage,
        string $direction,
        int    $boundaryPage,
        array  $options
    ): self {
        global $DB;

        // Cancel any stale active cursor for the same slot before creating a new one
        $DB->update(self::getTable(), ['status' => self::STATUS_CANCELLED], [
            'connections_id' => $connectionId,
            'resource_type'  => $resourceType,
            'options_hash'   => $optionsHash,
            'status'         => self::STATUS_ACTIVE,
        ]);

        $DB->insert(self::getTable(), [
            'connections_id' => $connectionId,
            'resource_type'  => $resourceType,
            'options_hash'   => $optionsHash,
            'current_page'   => $startPage,
            'direction'      => $direction,
            'boundary_page'  => $boundaryPage,
            'created_total'  => 0,
            'scanned_total'  => 0,
            'status'         => self::STATUS_ACTIVE,
            'options_json'   => json_encode($options),
            'started_at'     => date('Y-m-d H:i:s'),
            'updated_at'     => date('Y-m-d H:i:s'),
        ]);

        $cursor = new self();
        $cursor->getFromDB((int) $DB->insertId());
        return $cursor;
    }

    // ------------------------------------------------------------------ //
    // State management
    // ------------------------------------------------------------------ //

    public function advance(int $newPage, int $addCreated, int $addScanned): void
    {
        global $DB;
        $DB->update(self::getTable(), [
            'current_page'  => $newPage,
            'created_total' => (int) ($this->fields['created_total'] ?? 0) + $addCreated,
            'scanned_total' => (int) ($this->fields['scanned_total'] ?? 0) + $addScanned,
            'updated_at'    => date('Y-m-d H:i:s'),
        ], ['id' => (int) $this->fields['id']]);

        $this->fields['current_page']  = $newPage;
        $this->fields['created_total'] = (int) ($this->fields['created_total'] ?? 0) + $addCreated;
        $this->fields['scanned_total'] = (int) ($this->fields['scanned_total'] ?? 0) + $addScanned;
    }

    public function complete(): void
    {
        global $DB;
        $DB->update(self::getTable(), [
            'status'     => self::STATUS_COMPLETED,
            'updated_at' => date('Y-m-d H:i:s'),
        ], ['id' => (int) $this->fields['id']]);
        $this->fields['status'] = self::STATUS_COMPLETED;
    }

    public function cancel(): void
    {
        global $DB;
        $DB->update(self::getTable(), [
            'status'     => self::STATUS_CANCELLED,
            'updated_at' => date('Y-m-d H:i:s'),
        ], ['id' => (int) $this->fields['id']]);
        $this->fields['status'] = self::STATUS_CANCELLED;
    }

    public function currentPage(): int   { return (int) ($this->fields['current_page']  ?? 1); }
    public function direction(): string  { return (string) ($this->fields['direction']   ?? 'forward'); }
    public function boundaryPage(): int  { return (int) ($this->fields['boundary_page'] ?? 1); }
    public function createdTotal(): int  { return (int) ($this->fields['created_total'] ?? 0); }
    public function scannedTotal(): int  { return (int) ($this->fields['scanned_total'] ?? 0); }
    public function isActive(): bool     { return ($this->fields['status'] ?? '') === self::STATUS_ACTIVE; }
    public function optionsJson(): array
    {
        $v = $this->fields['options_json'] ?? '{}';
        return is_string($v) ? (json_decode($v, true) ?? []) : [];
    }

    // ------------------------------------------------------------------ //
    // Bulk operations
    // ------------------------------------------------------------------ //

    public static function cancelForConnection(int $connectionId, string $resourceType = ''): void
    {
        global $DB;
        $where = ['connections_id' => $connectionId, 'status' => self::STATUS_ACTIVE];
        if ($resourceType !== '') {
            $where['resource_type'] = $resourceType;
        }
        $DB->update(self::getTable(), ['status' => self::STATUS_CANCELLED], $where);
    }

    // ------------------------------------------------------------------ //
    // Schema
    // ------------------------------------------------------------------ //

    public static function install(Migration $migration): void
    {
        global $DB;

        $ks   = DBConnection::getDefaultPrimaryKeySignOption();
        $cs   = DBConnection::getDefaultCharset();
        $coll = DBConnection::getDefaultCollation();
        $table = self::getTable();

        if (!$DB->tableExists($table)) {
            $migration->displayMessage("Installing $table");
            $DB->doQueryOrDie(<<<SQL
            CREATE TABLE IF NOT EXISTS `$table` (
                `id`             int {$ks} NOT NULL AUTO_INCREMENT,
                `connections_id` int {$ks} NOT NULL DEFAULT 0,
                `resource_type`  varchar(64)  NOT NULL DEFAULT 'incidents',
                `options_hash`   varchar(32)  NOT NULL DEFAULT '',
                `current_page`   int          NOT NULL DEFAULT 1,
                `direction`      varchar(16)  NOT NULL DEFAULT 'forward',
                `boundary_page`  int          NOT NULL DEFAULT 1,
                `created_total`  int          NOT NULL DEFAULT 0,
                `scanned_total`  int          NOT NULL DEFAULT 0,
                `status`         varchar(16)  NOT NULL DEFAULT 'active',
                `options_json`   text,
                `started_at`     timestamp    NOT NULL,
                `updated_at`     timestamp    NOT NULL,
                PRIMARY KEY (`id`),
                KEY `slot` (`connections_id`, `resource_type`, `options_hash`, `status`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$cs} COLLATE={$coll} ROW_FORMAT=DYNAMIC;
            SQL, $DB->error());
        }
    }

    public static function uninstall(Migration $migration): void
    {
        $migration->displayMessage('Uninstalling ' . self::getTable());
        $migration->dropTable(self::getTable());
    }
}

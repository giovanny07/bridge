<?php

namespace GlpiPlugin\Bridge\Migration;

use CommonDBTM;
use DBConnection;
use Migration;

/**
 * Audit log + deduplication guard for every migrated resource.
 *
 * One row per migration attempt. Multiple failed rows for the same
 * source_id are allowed (full audit trail). Dedup logic looks only
 * at rows with status='success'.
 *
 * Purge operations remove rows so the migration engine re-processes
 * the corresponding source records on the next run.
 */
class MigrationRecord extends CommonDBTM
{
    public static $rightname = 'config';

    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED  = 'failed';
    public const STATUS_SKIPPED = 'skipped';

    public static function getTable($classname = null): string
    {
        return 'glpi_plugin_bridge_migrations';
    }

    // ------------------------------------------------------------------ //
    // Dedup / logging
    // ------------------------------------------------------------------ //

    public static function isAlreadyMigrated(int $connectionId, string $sourceType, string $sourceId): bool
    {
        global $DB;
        foreach ($DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => [
                'connections_id' => $connectionId,
                'source_type'    => $sourceType,
                'source_id'      => $sourceId,
                'status'         => self::STATUS_SUCCESS,
            ],
            'LIMIT' => 1,
        ]) as $_) {
            return true;
        }
        return false;
    }

    public static function log(
        int    $connectionId,
        string $sourceType,
        string $sourceId,
        string $sourceNumber,
        string $status,
        int    $ticketId = 0,
        string $errorMessage = ''
    ): void {
        global $DB;
        $DB->insert(self::getTable(), [
            'connections_id' => $connectionId,
            'source_type'    => $sourceType,
            'source_id'      => $sourceId,
            'source_number'  => $sourceNumber,
            'tickets_id'     => $ticketId,
            'status'         => $status,
            'error_message'  => $errorMessage !== '' ? $errorMessage : null,
            'migrated_at'    => date('Y-m-d H:i:s'),
            'migrated_by'    => (int) ($_SESSION['glpiID'] ?? 0),
        ]);
    }

    // ------------------------------------------------------------------ //
    // Purge operations
    // ------------------------------------------------------------------ //

    /** Purge all records for a connection + type (full reset for re-run). */
    public static function purgeAll(int $connectionId, string $sourceType = ''): int
    {
        global $DB;
        $where = ['connections_id' => $connectionId];
        if ($sourceType !== '') {
            $where['source_type'] = $sourceType;
        }
        $count = self::countWhere($connectionId, $sourceType, '');
        $DB->delete(self::getTable(), $where);
        return $count;
    }

    /** Purge a specific list of record IDs for a connection. */
    public static function purgeByIds(int $connectionId, array $ids): int
    {
        global $DB;
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (empty($ids)) {
            return 0;
        }
        $DB->delete(self::getTable(), ['connections_id' => $connectionId, 'id' => $ids]);
        return count($ids);
    }

    /** Purge only failed records so they can be retried. */
    public static function purgeFailed(int $connectionId, string $sourceType = ''): int
    {
        global $DB;
        $where = ['connections_id' => $connectionId, 'status' => self::STATUS_FAILED];
        if ($sourceType !== '') {
            $where['source_type'] = $sourceType;
        }
        $count = self::countWhere($connectionId, $sourceType, self::STATUS_FAILED);
        $DB->delete(self::getTable(), $where);
        return $count;
    }

    /** Purge records migrated before a given date. */
    public static function purgeOlderThan(int $connectionId, string $date): int
    {
        global $DB;
        $count = 0;
        foreach ($DB->request([
            'COUNT' => 'id',
            'FROM'  => self::getTable(),
            'WHERE' => ['connections_id' => $connectionId, ['migrated_at' => ['<', $date]]],
        ]) as $row) {
            $count = (int) $row['id'];
        }
        $DB->delete(self::getTable(), [
            'connections_id' => $connectionId,
            ['migrated_at'   => ['<', $date]],
        ]);
        return $count;
    }

    private static function countWhere(int $connectionId, string $sourceType, string $status): int
    {
        global $DB;
        $where = ['connections_id' => $connectionId];
        if ($sourceType !== '') $where['source_type'] = $sourceType;
        if ($status !== '')     $where['status']      = $status;
        foreach ($DB->request(['COUNT' => 'id', 'FROM' => self::getTable(), 'WHERE' => $where]) as $row) {
            return (int) $row['id'];
        }
        return 0;
    }

    // ------------------------------------------------------------------ //
    // History query
    // ------------------------------------------------------------------ //

    public static function getHistory(int $connectionId, array $filters = [], int $limit = 100, int $offset = 0): array
    {
        global $DB;
        $where = ['connections_id' => $connectionId];
        if (!empty($filters['source_type'])) {
            $where['source_type'] = $filters['source_type'];
        }
        if (!empty($filters['status'])) {
            // 'warning' is a virtual filter: success records that have a non-empty error_message
            if ($filters['status'] === 'warning') {
                $where['status']       = self::STATUS_SUCCESS;
                $where['NOT']          = ['error_message' => null];
                $where[['error_message' => ['<>', '']]];
            } else {
                $where['status'] = $filters['status'];
            }
        }

        $rows = [];
        foreach ($DB->request([
            'FROM'   => self::getTable(),
            'WHERE'  => $where,
            'ORDER'  => ['migrated_at DESC'],
            'LIMIT'  => $limit,
            'START'  => $offset,
        ]) as $row) {
            $rows[] = $row;
        }
        return $rows;
    }

    public static function getSummary(int $connectionId, string $sourceType = ''): array
    {
        global $DB;
        $where = ['connections_id' => $connectionId];
        if ($sourceType !== '') {
            $where['source_type'] = $sourceType;
        }

        $summary = ['total' => 0, 'success' => 0, 'failed' => 0, 'skipped' => 0, 'warning' => 0];
        foreach ($DB->request(['FROM' => self::getTable(), 'WHERE' => $where]) as $row) {
            $summary['total']++;
            $summary[$row['status']] = ($summary[$row['status']] ?? 0) + 1;
            // Count success records that have warnings stored in error_message
            if ($row['status'] === self::STATUS_SUCCESS && !empty($row['error_message'])) {
                $summary['warning']++;
            }
        }
        return $summary;
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
                `source_type`    varchar(64) NOT NULL DEFAULT 'incidents',
                `source_id`      varchar(64) NOT NULL DEFAULT '',
                `source_number`  varchar(64) NOT NULL DEFAULT '',
                `tickets_id`     int {$ks} NOT NULL DEFAULT 0,
                `status`         varchar(16) NOT NULL DEFAULT 'success',
                `error_message`  text,
                `migrated_at`    datetime NOT NULL,
                `migrated_by`    int {$ks} NOT NULL DEFAULT 0,
                PRIMARY KEY (`id`),
                KEY `connections_id` (`connections_id`),
                KEY `source_lookup`  (`connections_id`, `source_type`, `source_id`),
                KEY `status`         (`status`),
                KEY `migrated_at`    (`migrated_at`)
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

<?php

namespace GlpiPlugin\Bridge\Migration;

use DBConnection;
use Migration;

/**
 * Operational log for a BridgeJob.
 *
 * One row is appended after every processed chunk.  This gives full
 * visibility into throughput, errors and cursor position without
 * bloating the jobs table.
 */
class JobLog
{
    public static function getTable(): string
    {
        return 'glpi_plugin_bridge_job_logs';
    }

    /**
     * Appends one log entry for a completed chunk.
     *
     * @param int    $jobId       Parent job ID
     * @param int    $chunk       Chunk sequence number (1-based)
     * @param int    $pagesRead   API pages fetched in this chunk
     * @param int    $scanned     Records scanned
     * @param int    $created     Records created in GLPI
     * @param int    $failed      Records that failed
     * @param int    $skipped     Records already migrated (skipped)
     * @param int    $durationMs  Wall-clock duration of the chunk in ms
     * @param int    $cursorPage  Cursor page after the chunk (0 = exhausted)
     * @param array  $errors      Sample of error messages from this chunk
     * @param array  $metrics     Detailed pipeline metrics collected by MigrationResult
     */
    public static function append(
        int   $jobId,
        int   $chunk,
        int   $pagesRead,
        int   $scanned,
        int   $created,
        int   $failed,
        int   $skipped,
        int   $durationMs,
        int   $cursorPage,
        array $errors = [],
        array $metrics = []
    ): void {
        global $DB;

        $DB->insert(self::getTable(), [
            'jobs_id'      => $jobId,
            'chunk'        => $chunk,
            'logged_at'    => date('Y-m-d H:i:s'),
            'pages_read'   => $pagesRead,
            'scanned'      => $scanned,
            'created'      => $created,
            'failed'       => $failed,
            'skipped'      => $skipped,
            'duration_ms'  => $durationMs,
            'cursor_page'  => $cursorPage,
            'errors_json'  => $errors ? json_encode(array_slice($errors, 0, 20)) : null,
            'metrics_json' => $metrics ? json_encode($metrics) : null,
        ]);
    }

    /**
     * Returns all log rows for a job, oldest first.
     */
    public static function forJob(int $jobId): array
    {
        global $DB;
        $rows = [];
        foreach ($DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => ['jobs_id' => $jobId],
            'ORDER' => ['chunk ASC'],
        ]) as $row) {
            $rows[] = $row;
        }
        return $rows;
    }

    /**
     * Returns the chunk count for a job.
     */
    public static function chunkCount(int $jobId): int
    {
        global $DB;
        foreach ($DB->request([
            'COUNT' => 'id',
            'FROM'  => self::getTable(),
            'WHERE' => ['jobs_id' => $jobId],
        ]) as $row) {
            return (int) $row['id'];
        }
        return 0;
    }

    // ------------------------------------------------------------------ //
    // Schema
    // ------------------------------------------------------------------ //

    public static function install(Migration $migration): void
    {
        global $DB;

        $ks    = DBConnection::getDefaultPrimaryKeySignOption();
        $cs    = DBConnection::getDefaultCharset();
        $coll  = DBConnection::getDefaultCollation();
        $table = self::getTable();

        if (!$DB->tableExists($table)) {
            $migration->displayMessage("Installing $table");
            $DB->doQueryOrDie(<<<SQL
            CREATE TABLE IF NOT EXISTS `$table` (
                `id`           int {$ks} NOT NULL AUTO_INCREMENT,
                `jobs_id`      int {$ks} NOT NULL DEFAULT 0,
                `chunk`        int          NOT NULL DEFAULT 0,
                `logged_at`    timestamp    NOT NULL,
                `pages_read`   int          NOT NULL DEFAULT 0,
                `scanned`      int          NOT NULL DEFAULT 0,
                `created`      int          NOT NULL DEFAULT 0,
                `failed`       int          NOT NULL DEFAULT 0,
                `skipped`      int          NOT NULL DEFAULT 0,
                `duration_ms`  int          NOT NULL DEFAULT 0,
                `cursor_page`  int          NOT NULL DEFAULT 0,
                `errors_json`  text,
                `metrics_json` text,
                PRIMARY KEY (`id`),
                KEY `jobs_id` (`jobs_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$cs} COLLATE={$coll} ROW_FORMAT=DYNAMIC;
            SQL, $DB->error());
        } elseif (!$DB->fieldExists($table, 'metrics_json')) {
            $migration->addField($table, 'metrics_json', 'text');
            $migration->executeMigration();
        }
    }

    public static function uninstall(Migration $migration): void
    {
        $migration->displayMessage('Uninstalling ' . self::getTable());
        $migration->dropTable(self::getTable());
    }
}

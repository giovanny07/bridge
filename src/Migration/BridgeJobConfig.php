<?php

namespace GlpiPlugin\Bridge\Migration;

/**
 * Central registry of tuning constants for the Bridge migration system.
 *
 * All operational parameters live here so they can be adjusted in one place
 * and referenced consistently across BridgeJob, MigrationCursor, and
 * MigrationEngine without scattered magic numbers.
 */
final class BridgeJobConfig
{
    // ------------------------------------------------------------------ //
    // Cron scheduling
    // ------------------------------------------------------------------ //

    /** Seconds between cron ticks for each ProcessJobs slot. */
    public const CRON_INTERVAL_SECONDS = 60;

    /** Days to retain cron log entries in glpi_crontasklogs. */
    public const CRON_LOGS_LIFETIME_DAYS = 7;

    // ------------------------------------------------------------------ //
    // Job lifecycle
    // ------------------------------------------------------------------ //

    /** Minutes without a heartbeat before a RUNNING job is declared zombie. */
    public const ZOMBIE_MINUTES = 15;

    // ------------------------------------------------------------------ //
    // Chunking / pagination
    // ------------------------------------------------------------------ //

    /** API pages processed per cron tick in chronological (from_date) mode.
     *  20 pages × 50 records × ~300 ms API ≈ 6 s per tick — stays well under
     *  PHP max_execution_time. Raise only after confirming API latency allows it. */
    public const CHUNK_PAGES = 20;

    /** Records requested per API page. SolarWinds max is 100; 50 is safe. */
    public const PER_PAGE = 50;

    // ------------------------------------------------------------------ //
    // Eje 1 — parallel jobs (activated in Etapa 2)
    // ------------------------------------------------------------------ //

    /** When true, separate cron slots (ProcessIncidents / ProcessChanges /
     *  ProcessProblems) are active and the legacy ProcessJobs slot becomes a
     *  no-op. Set to false only to revert to the single-slot legacy mode. */
    public const PARALLEL_JOBS = true;

    // ------------------------------------------------------------------ //
    // Eje 2 — parallel API pages (activated in Etapa 5)
    // ------------------------------------------------------------------ //

    /** Global default for the number of API pages fetched concurrently within a
     *  single job chunk via curl_multi. 1 = fully sequential (safe default).
     *  Individual connections override this via their parallel_api_pages field.
     *  Raise only after confirming the target system allows burst requests. */
    public const PARALLEL_API_PAGES = 1;

    /** Hard ceiling for PARALLEL_API_PAGES — never exceed this regardless of
     *  per-connection configuration, to avoid API bans. */
    public const PARALLEL_API_MAX = 8;

    /** Seconds to sleep before retrying a page that returned HTTP 429.
     *  Gives the remote API time to reset its rate-limit window.
     *  Set to 0 to disable the pause (not recommended in production). */
    public const RATE_LIMIT_BACKOFF_SECONDS = 5;
}

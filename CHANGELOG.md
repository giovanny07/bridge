# Changelog

All notable changes to the Bridge plugin are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.5.0] - 2026-06-26

### Added
- **Parallel API pages (Eje 2)** — `SolarWindsClient::listPagesBatch()` fetches
  multiple API pages concurrently using `curl_multi_exec` via the `HttpBatch`
  transport. `MigrationEngine` groups pages into waves and dispatches them as a
  batch instead of sequential single-page requests.
- **Per-connection `parallel_api_pages`** — new `tinyint` column on
  `glpi_plugin_bridge_connections` (1–8, default 1). Configurable in the
  connection form; `BridgeJob` passes it to the engine constructor.
- **HTTP 429 rate-limit backoff** — when any page in a batch returns 429 the
  engine sleeps `BridgeJobConfig::RATE_LIMIT_BACKOFF_SECONDS` (default 5 s) then
  re-fetches affected pages sequentially.
- `BridgeJobConfig::PARALLEL_API_MAX = 8` — hard ceiling for parallel pages.
- `BridgeJobConfig::RATE_LIMIT_BACKOFF_SECONDS = 5`.
- `ConnectorInterface::listPagesBatch()` — new contract method.
- `SolarWindsClientBatchTest` — 15 unit tests for `listPagesBatch()` using
  transport injection (no real network).
- `ajax/edit_form.php` — AJAX endpoint that returns the connection form HTML so
  the Config tab can load it in-place without page navigation.

### Changed
- **Edit connection loads via AJAX** inside the Bridge Config tab. Previously the
  edit link navigated to `config.php?bridge_connection_id=N`, which GLPI's
  `Html::header()` 302-redirected back to the tab, stripping the ID and leaving
  the form empty. The form now fetches `ajax/edit_form.php?id=N` and injects the
  HTML into the right column.
- `ConfigPage::showConnectionForm()` promoted to `public static`.
- `config.form.php` redirects post-save directly to the Config tab URL instead
  of going through `config.php` (removed an unnecessary 302 hop).
- `MigrationEngine` accepts a ninth constructor parameter `$parallelApiPages`
  (default `BridgeJobConfig::PARALLEL_API_PAGES`) and uses `buildWavePages()` /
  `listBatchWave()` for wave-based fetching.

### Fixed
- **Test connection result was silently discarded** — the JS handler looked for
  `#bridge-test-result-{id}` but that element was never rendered. Added the div
  to each connection row so results are visible after a test.
- Confirm-dialog handler (`data-bridge-confirm`) now works correctly on
  dynamically injected buttons (the delete button in the AJAX edit form).

## [1.4.7] - 2026-06-20

### Added
- **HttpBatch** (`src/Connector/HttpBatch.php`) — reusable `curl_multi_exec`
  wrapper; accepts a map of `{key → [url, headers]}`, fans them out in
  configurable waves, returns `{key → [body, status_code, error]}`. Transport
  callable injection allows unit tests to fake responses without network I/O.
- **BridgeJobConfig** (`src/Migration/BridgeJobConfig.php`) — single source of
  truth for all operational constants: `CRON_INTERVAL_SECONDS`, `CHUNK_PAGES`,
  `PER_PAGE`, `ZOMBIE_MINUTES`, `PARALLEL_JOBS`, `PARALLEL_API_PAGES`.
- **Parallel cron slots (Eje 1)** — three dedicated automatic actions
  (`ProcessIncidents`, `ProcessChanges`, `ProcessProblems`) run concurrently;
  each only picks up jobs whose `resource_type` matches. `ProcessJobs` becomes a
  no-op when `BridgeJobConfig::PARALLEL_JOBS = true`.
- **Connection-list observability** — colour-coded status badges and a "View job"
  link for active jobs on the connections list via `BridgeJob::getConnectionSummary()`.

### Fixed
- Approval date and status mapping for Changes: `approved_at` now written to
  `validation_date` from the correct source field.
- Removed spurious `Type` metadata injected into Change and Problem descriptions.
- Inline images in followups referencing Samanage URLs now replaced with GLPI
  document URLs.

## [1.4.6] - 2026-06-15

### Added
- **Change task migration** — tasks fetched from `/changes/{id}/tasks.json` and
  written as `ChangeTask` records. Task failures are counted without aborting the
  Change.
- **Resolve by ticket number** — `#N` notation accepted in migration-by-ID mode;
  numbers are resolved to internal source IDs automatically.
- **Change approval routing** — Changes in approval states route to
  `ChangeValidation` instead of generic followups.

## [1.1.2] - 2026-06-09

### Fixed
- Editing a specific connection now works. The connection management UI is served
  as a standalone page (`front/config.php`) instead of a GLPI Config tab. GLPI
  loads tab content through an AJAX endpoint that strips custom query params, so
  `?bridge_connection_id=N` never reached `ConfigPage::show()` and the edit form
  always rendered empty. As a normal page, the parameter is honoured.

### Changed
- The "Configure" link and all in-app config links open the standalone Bridge
  connections page rather than the General Setup → Bridge tab.

## [1.1.1] - 2026-06-09

### Fixed
- Migration history page: search, status filters, pagination, and "clear search"
  links were built as relative `?id=...` URLs, which resolved against GLPI's root
  `<base href>` and dropped the plugin path (e.g. `/?id=1`). They now use the
  absolute, plugin-dir-aware self URL.

## [1.1.0] - 2026-06-09

First stable release. Core migration features, the background job system, and UX
hardening are complete.

### Added
- Migration preflight: read-only sample, dedupe, and candidate blocking before a job is created.
- Mapping quality report (clean / fallback / unresolved summary with warning details).
- Preflight CSV export — portable remediation report for mapping cleanup.
- Background job system: `BridgeJob` + GLPI `CronTask` (60s polling, external mode).
- Resumable migrations via cursor-based chunked processing (40 pages/chunk).
- Live progress UI: real-time feed, stats, and per-chunk logs.
- Job management: cancel, retry job, retry failed records, and job list.
- Incident, Problem, and Change migration with followups, solution, plans, and attachments.
- User synchronisation from the source system.
- Migration history with search, pagination, per-row purge, and partial status.

### Changed
- Hardening: concurrent job blocking, input validation, and cascade delete on connection removal.

## [1.0.3] - 2026-06-08

### Fixed
- Plugin front-controller URLs now derive from `Connection::getPluginBaseURL()`
  (`$CFG_GLPI['url_base']` + marketplace/plugins detection) instead of
  `dirname($_SERVER['SCRIPT_NAME'])`. Under GLPI 11 every request routes through
  `/index.php`, so the old logic collapsed plugin links to the domain root
  (e.g. `/migrate.php`) and produced 404s.

[Unreleased]: https://github.com/Imagunet-S-A-S/bridge/compare/v1.1.2...HEAD
[1.1.2]: https://github.com/Imagunet-S-A-S/bridge/compare/v1.1.1...v1.1.2
[1.1.1]: https://github.com/Imagunet-S-A-S/bridge/compare/v1.1.0...v1.1.1
[1.1.0]: https://github.com/Imagunet-S-A-S/bridge/compare/v1.0.3...v1.1.0
[1.0.3]: https://github.com/Imagunet-S-A-S/bridge/releases/tag/v1.0.3

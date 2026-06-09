# Changelog

All notable changes to the Bridge plugin are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

### Changed

### Fixed

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

[Unreleased]: https://github.com/Imagunet-S-A-S/bridge/compare/v1.1.1...HEAD
[1.1.1]: https://github.com/Imagunet-S-A-S/bridge/compare/v1.1.0...v1.1.1
[1.1.0]: https://github.com/Imagunet-S-A-S/bridge/compare/v1.0.3...v1.1.0
[1.0.3]: https://github.com/Imagunet-S-A-S/bridge/releases/tag/v1.0.3

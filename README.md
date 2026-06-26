# Bridge — GLPI 11 Plugin

Bridge is a **GLPI 11** plugin for migrating ITSM data from external platforms into GLPI. It currently supports **SolarWinds Service Desk (Samanage)** and is designed to be extended to other source systems.

---

## Status — v1.5.0

| Feature | Status |
|---------|--------|
| Plugin base (setup, hooks, PSR-4 autoload) | Done |
| Connection management with GLPIKey encryption | Done |
| Inline connection test with visible result | Done |
| Multi-resource discovery scan (read-only) | Done |
| Entity / category / group / user resolver | Done |
| Fuzzy entity matching (accents, legal suffixes, parentheticals, abbreviations, quotes) | Done |
| Actionable dry-run preview | Done |
| Incident migration — Ticket + followups + solution + attachments | Done |
| Problem migration — ITILProblem + cause + symptoms + workaround | Done |
| Change migration — ITILChange + rollout + backout + checklist plans | Done |
| Change task migration | Done |
| Migration by ticket number or internal ID | Done |
| Source traceability prefix `[ SD #N ]` in title | Done |
| Public / private comment preservation (`is_private`) | Done |
| External requesters stored as `alternative_email` | Done |
| Inline image replacement with GLPI document URLs | Done |
| Incident vs Service Request type mapping | Done |
| User synchronisation from source system | Done |
| Migration history with search, pagination, per-row purge, and partial status | Done |
| **Migration preflight** — read-only sample, dedupe, candidate blocking before job creation | Done |
| **Mapping quality report** — clean / fallback / unresolved summary with warning details | Done |
| **Preflight CSV export** — portable remediation report for mapping cleanup | Done |
| **Background job system** — BridgeJob + GLPI CronTask (60 s polling) | Done |
| **Parallel cron slots** — ProcessIncidents / ProcessChanges / ProcessProblems run concurrently | Done |
| **Resumable migrations** — cursor-based chunked processing | Done |
| **Live progress UI** — real-time feed, stats, logs per chunk | Done |
| **Job management** — cancel, retry job, retry failed records, job list | Done |
| **Hardening** — concurrent job blocking, input validation, cascade delete | Done |
| **Parallel API pages** — configurable per-connection concurrency (1–8) via `curl_multi_exec` | Done |
| **Rate-limit backoff** — automatic retry with sleep on HTTP 429 | Done |

---

## Requirements

| | |
|---|---|
| GLPI | 11.0.0 – 11.0.99 |
| PHP | >= 8.1 |
| PHP extension | `curl` (streams fallback included) |

---

## Installation

### From release (recommended)

Download and unpack the latest published build into your GLPI plugins directory:

```bash
curl -fsSL https://imagu-binaries.s3.us-east-1.amazonaws.com/bridge/install.sh | bash
# pin a version:   curl -fsSL .../bridge/install.sh | VERSION=v1.1.0 bash
# custom dir:      curl -fsSL .../bridge/install.sh | PLUGINS_DIR=/var/lib/glpi/plugins bash
# uninstall:       curl -fsSL .../bridge/install.sh | UNINSTALL=true bash
```

The installer auto-detects the GLPI plugins directory (or use `PLUGINS_DIR`).

### From source (development)

```bash
cd /var/lib/glpi/plugins
git clone git@github.com:Imagunet-S-A-S/bridge.git bridge
cd bridge
composer install --no-dev
```

In GLPI: **Setup > Plugins > Bridge > Install > Enable**.

The installation creates the following tables:

- `glpi_plugin_bridge_connections` — source system connection records
- `glpi_plugin_bridge_migrations` — per-record migration audit log
- `glpi_plugin_bridge_cursors` — resumable migration cursors
- `glpi_plugin_bridge_jobs` — background migration job queue
- `glpi_plugin_bridge_job_logs` — operational log per chunk

It also registers four GLPI automatic actions:

| Action | Role |
|--------|------|
| `Bridge ProcessIncidents` | Processes queued incident migration jobs |
| `Bridge ProcessChanges` | Processes queued change migration jobs |
| `Bridge ProcessProblems` | Processes queued problem migration jobs |
| `Bridge ProcessJobs` | Legacy no-op slot (kept for upgrade safety) |

> **Important:** After installing, set up the OS-level cron so GLPI automatic actions run:
>
> ```bash
> # AlmaLinux / RHEL — install cronie if needed
> dnf install -y cronie && systemctl enable --now crond
>
> # Add to /etc/cron.d/glpi  (runs every minute as apache user)
> echo "* * * * * apache /usr/bin/php /usr/share/glpi/front/cron.php" > /etc/cron.d/glpi
> ```

---

## Code structure

```
bridge/
├── setup.php                          Plugin declaration and GLPI hooks
├── hook.php                           Table install / uninstall
├── ajax/
│   ├── edit_form.php                  AJAX endpoint — returns connection form HTML
│   ├── job_status.php                 AJAX endpoint — returns live job progress JSON
│   └── test_connection.php            AJAX endpoint — connectivity check
├── front/
│   ├── config.php                     Redirect entry point to Bridge Config tab
│   ├── config.form.php                POST handler (add / update / purge connection)
│   ├── scan.php                       Read-only discovery scan
│   ├── dryrun.php                     Dry-run preview
│   ├── migrate.php                    Creates migration job and redirects to status
│   ├── migration_history.php          History view with purge / retry
│   ├── job_status.php                 Live job progress page
│   ├── jobs.php                       Job list per connection
│   └── sync_users.php                 User synchronisation front controller
├── src/
│   ├── Config.php                     Bridge tab in GLPI General Setup
│   ├── Connection.php                 CommonDBTM model for connections
│   ├── Contract/
│   │   ├── ConnectorInterface.php     Contract every connector must implement
│   │   └── NormalizerInterface.php    Contract for field mapping per system
│   ├── Connector/
│   │   ├── ConnectorFactory.php       Builds connector / normalizer by system_type
│   │   ├── HttpBatch.php              curl_multi_exec wrapper for parallel HTTP requests
│   │   └── SolarWinds/
│   │       ├── SolarWindsClient.php   HTTP client for Samanage API (sequential + batch)
│   │       └── SamanageNormalizer.php Field mapping for SolarWinds
│   ├── Resolver/
│   │   └── GlpiResolver.php           Resolves entities / categories / groups / users by name
│   ├── Migration/
│   │   ├── BridgeJob.php              Background job record (CommonDBTM) + lifecycle
│   │   ├── BridgeJobConfig.php        Central constants (chunk sizes, timeouts, concurrency)
│   │   ├── IncidentMapper.php         Combines normalizer + resolver into GLPI input
│   │   ├── JobLog.php                 Operational log record (CommonDBTM)
│   │   ├── MappedIncident.php         Value object: ITIL item + followups + solution + warnings
│   │   ├── MigrationCursor.php        Resumable pagination cursor (CommonDBTM)
│   │   ├── MigrationEngine.php        Orchestrates migration with wave-based API fetching
│   │   ├── MigrationRecord.php        Audit log (CommonDBTM)
│   │   ├── MigrationResult.php        Result: created / failed / skipped counts + timing stats
│   │   ├── UserSyncer.php             Synchronises source users into GLPI
│   │   └── UserSyncResult.php         User sync result value object
│   └── Page/
│       ├── ConfigPage.php             UI: connection list and AJAX-loaded edit form
│       ├── DryRunPage.php             UI: resource selector and resolution table
│       ├── MigratePage.php            UI: migration form and results
│       ├── HistoryPage.php            UI: history with search, pagination, and actions
│       ├── JobStatusPage.php          UI: live job progress with polling
│       └── SyncUsersPage.php          UI: user sync form and results
├── locales/                           Translations: en_GB, es_ES, pt_BR
├── tools/
│   └── compile-mo.php                 Compiles .po files to .mo without msgfmt
└── tests/
    ├── bootstrap.php                  GLPI stubs for unit tests (no live instance needed)
    ├── units/                         Unit tests (no network, no GLPI)
    └── api/                           Contract tests against the live API
```

---

## Workflow

### Configure a connection

1. Go to **Setup > General Setup > Bridge tab**.
2. Fill in: name, base URL, authentication type, token or secret.
3. Set a fallback entity (used when a source site cannot be matched by name).
4. Set a fallback group (used when the assignee cannot be resolved).
5. Click **Test** to verify authentication and connectivity.
6. Click **Scan** to preview a sample of raw records from the source system.

### Dry-run

Select **Dry-run** on a connection to preview how records will be resolved — entity, category, group, and requester — without writing anything to GLPI.

### Migration

Open the **Migrate** form for a connection. Two modes are available.

**By filters**

Select a status, a time period (recent, from date, incremental, or manual page), and a record limit. The API returns records newest-first; the time period selector abstracts away the underlying page offset.

**By source IDs**

Enter a comma-separated list of ticket numbers (e.g. `#12345`) or internal source IDs. The engine resolves ticket numbers to their internal IDs automatically before fetching.

**Content options**

| Option | Description |
|--------|-------------|
| Default requester | GLPI user assigned when the source record has no requester email |
| Comments as followups | Creates one ITILFollowup per comment |
| Attachments as documents | Downloads files and links them as GLPI Documents |
| Preserve private flag | Keeps the `is_private` state of source comments (default: on) |

**What gets created per record**

- An ITIL item (Ticket or Problem) with entity, category, assignee, and requester resolved by name or email.
- One ITILFollowup per comment, ordered by the original source date.
- An ITILSolution from `resolution_description`, `resolution_code`, or the status name as a minimal fallback.
- One Document per downloaded attachment, linked to the corresponding followup.
- For Problems: cause content, symptom content, and a private workaround followup when the source fields are present.

### Migration history

The history view shows all migration attempts with status (success / partial / failed / skipped), a direct link to the created GLPI item, and the resolver warnings for partial records. Records can be selected individually and purged for retry, or purged in bulk.

### User synchronisation

The **Sync users** action on a connection imports source system users into GLPI as user + email records, enabling the resolver to match them on future migrations. Supports dry-run preview, role filtering, and optional update of existing records.

---

## Actor resolution

### Assignee

1. If the source assignee is a user: resolved by email in `glpi_useremails`.
2. If the source assignee is a group: resolved by name in `glpi_groups` (fuzzy, accent-insensitive).
3. If unresolved: the fallback group configured on the connection is used.

### Requester

| Situation | Result in GLPI |
|-----------|----------------|
| Email found in GLPI | User linked by ID |
| Email present but no GLPI account | Stored as `alternative_email` — visible in the item |
| No email in source, fallback configured | Fallback user from the migration form |
| No email and no fallback | Field left empty |

---

## Entity name matching

The resolver applies up to three normalisation passes to handle naming differences between source and destination systems:

1. Exact match after lowercasing and accent transliteration.
2. Match after stripping common legal suffixes (C.A., S.A., S.R.L., N.V., Inc.).
3. Match after stripping trailing parenthetical content (e.g. `Empresa (Former Name)`).

Additional normalisations applied before comparison: internal spaces in abbreviations (`C. A.` → `C.A.`), missing trailing periods (`C.A` → `C.A.`), quotation mark removal, and HTML entity decoding.

---

## Timeline behaviour in GLPI

- Items are ordered by `date_creation`, which is set to the original source timestamp so the timeline reflects the history of the source system, not the migration date.
- Closed or solved records with no explicit resolution text receive a minimal ITILSolution using the status label to keep the GLPI timeline internally consistent.
- Source comments marked as private are migrated as private followups when **Preserve private flag** is enabled.
- Links to other source-system records within comment bodies are stripped to plain text to avoid dead links in GLPI.

---

## SolarWinds Service Desk — API notes

### Authentication

The Samanage API requires the header `X-Samanage-Authorization: Bearer <token>`. The standard `Authorization: Bearer` header returns 401. The connector handles this automatically.

### Pagination

The API always returns records newest-first and does not honour `sort_order=asc`. Reaching historical records requires advancing the page offset. The migration form's time-period selector estimates the starting page from the target date.

### Field mapping — Incidents

| Samanage field | GLPI field | Notes |
|----------------|------------|-------|
| `number` + `name` | `name` | Prefixed as `[ SD #N ] title` |
| `is_service_request` | `type` | `false` → 1 (Incident), `true` → 2 (Service request) |
| `state` | `status` | See status map below |
| `priority` | `priority` | Low/Medium/High/Critical → 2/3/4/5 |
| `origin` | `requesttypes_id` | web → 1, api/external → 6, email → 7 |
| `site.name` | `entities_id` | Fuzzy name match |
| `category.name` / `subcategory.name` | `itilcategories_id` | Subcategory takes precedence |
| `assignee` | `_users_id_assign` / `_groups_id_assign` | Resolved by email or name |
| `requester.email` | `_users_id_requester` / `alternative_email` | See actor resolution |
| `created_at` | `date`, `date_creation` | Converted to server timezone |
| `updated_at` | `solvedate`, `closedate` | For solved / closed states |
| `resolution_description` | `ITILSolution.content` | Priority 1 |
| `resolution_code` | `ITILSolution.content` | Priority 2 |
| state label (fallback) | `ITILSolution.content` | Priority 3 — minimal entry |
| Comment `body` | `ITILFollowup.content` | Comments before the solution date |
| Comment `is_private` | `ITILFollowup.is_private` | Honoured when preserve flag is on |
| Comment `inline_attachments` | Document + `<img src>` replacement | Downloaded and re-linked to GLPI |
| Comment `attachments` | Document linked to followup | Filenames URL-decoded |

**Status map**

| Samanage state | GLPI status |
|----------------|-------------|
| Pending Assignment / New | 1 — New |
| En Proceso / Assigned / Gestión Proveedor | 2 — Assigned |
| Pendiente Acción Cliente / Waiting for Customer | 4 — Pending |
| Solucionado / Resolved | 5 — Solved |
| Closed | 6 — Closed |

### Field mapping — Changes

| Samanage field | GLPI field | Notes |
|----------------|------------|-------|
| `number` + `name` | `name` | Prefixed as `[ SD #N ] title` |
| `description_no_html` | `content` | — |
| `change_plan` | `rolloutplancontent` | Implementation / rollout plan |
| `rollback_plan` | `backoutplancontent` | Backout / rollback plan |
| `test_plan` | `checklistcontent` | Test / verification checklist |
| State | `status` | See Change status map below |
| `priority` | `priority` | Same mapping as Incidents |
| Dates | `date`, `solvedate`, `closedate` | Same logic as Incidents |
| Actors | `glpi_changes_users` / `glpi_changes_groups` | Same logic as Tickets |
| Change tasks | `ChangeTask` | Created from `/changes/{id}/tasks.json`; failures are counted without aborting the Change |

Preflight and dry-run previews show the detected task count for changes. The
preflight CSV export includes `tasks_count` so large batches can be reviewed
before creating GLPI records.

**Change status map**

| Samanage state | GLPI status |
|----------------|-------------|
| Solicitado | 1 — New |
| Pre Aprobado | 9 — Evaluation |
| Aprobado | 7 — Accepted |
| Iniciado | 2 — Assigned |
| Revisado | 12 — Qualification |
| Post Mortem | 12 — Qualification |
| Finalizado / Cerrado / Completed | 6 — Closed |
| Rechazado | 13 — Refused |
| Cancelado / Expirado | 14 — Cancelled |

### Field mapping — Problems

| Samanage field | GLPI field | Notes |
|----------------|------------|-------|
| `number` + `name` | `name` | Prefixed as `[ SD #N ] title` |
| `description_no_html` | `content` | — |
| `root_cause` | `causecontent` | — |
| `symptoms` | `symptomcontent` | — |
| `workaround` | Private ITILFollowup | No direct GLPI column |
| State / priority / dates | Same as Incidents | — |
| Actors | `glpi_problems_users` / `glpi_groups_problems` | Same logic as Tickets |

### Deduplication

Every migrated record is written to `glpi_plugin_bridge_migrations` with its `source_id`. Re-running a migration skips records that already have `status = success`.

---

## Parallel API pages

By default the migration engine fetches API pages one at a time (`PARALLEL_API_PAGES = 1`). Enable concurrent fetching by setting a higher value on the connection form.

| Setting | Where | Description |
|---------|-------|-------------|
| `parallel_api_pages` | Connection form (1–8) | Pages fetched simultaneously per cron wave |
| `BridgeJobConfig::PARALLEL_API_MAX` | `BridgeJobConfig.php` | Hard ceiling — never exceeded regardless of connection config |
| `BridgeJobConfig::RATE_LIMIT_BACKOFF_SECONDS` | `BridgeJobConfig.php` | Seconds to sleep before retrying a 429 page (default 5) |

**How waves work**

Each cron tick processes up to `CHUNK_PAGES` pages. With `parallel_api_pages = N`, the engine groups those pages into waves of N and fires them in parallel via `curl_multi_exec`. Pages that return HTTP 429 are re-fetched sequentially after the backoff sleep. Result order is preserved regardless of which response arrived first.

**Tuning advice**

Start at 1 (sequential). Raise to 2–4 only after confirming the target API tolerates burst requests. SolarWinds Service Desk (Samanage) may rate-limit aggressively on large instances — monitor for 429 responses in the job log before increasing further.

---

## Adding a new source system

1. Implement `ConnectorInterface` at `src/Connector/{System}/{System}Client.php`.
2. Implement `NormalizerInterface` at `src/Connector/{System}/{System}Normalizer.php`.
3. Register both in `ConnectorFactory::make()` and `makeNormalizer()`.
4. Add the system type to `Connection::getSupportedSystems()`.

The resolver, migration engine, UI, and history work without further changes.

---

## Tests

```bash
# Unit tests (262 tests, no network, no GLPI instance required)
composer test

# Contract tests against a live API
BRIDGE_API_URL=https://your-instance.example.com \
BRIDGE_API_TOKEN='<token>' \
composer test:api
```

---

## Translations

Supported locales: `en_GB`, `es_ES`, `pt_BR`. To recompile `.mo` files:

```bash
php tools/compile-mo.php
```

---

## Contributing & releases

See [CONTRIBUTING.md](CONTRIBUTING.md) for the development setup, translation
workflow, and how releases are cut (`tools/bump-version.sh` + tag → S3). Release
history is in [CHANGELOG.md](CHANGELOG.md).

---

## License

GPLv3+. See [LICENSE](LICENSE).

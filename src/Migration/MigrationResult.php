<?php

namespace GlpiPlugin\Bridge\Migration;

class MigrationResult
{
    /** @var array<array{number:string, tickets_id:int}> */
    public array $created = [];
    /** @var array<array{number:string, reason:string}> */
    public array $failed  = [];
    /** @var array<string> source numbers skipped (already migrated) */
    public array $skipped = [];
    /** @var array<int,array<string,mixed>> read-only preflight rows with mapping quality details */
    public array $preflightRows = [];
    /** @var array<string,int> aggregate mapping quality counters */
    public array $mappingQuality = [
        'ok'         => 0,
        'partial'    => 0,
        'unresolved' => 0,
        'duplicate'  => 0,
        'failed'     => 0,
    ];
    /** @var array<string,int> lightweight pipeline counters for observability */
    public array $stats = [
        'api_pages'              => 0,
        'scanned'                => 0,
        'date_matched'           => 0,
        'duplicates'             => 0,
        'queued'                 => 0,
        'comments_requests'      => 0,
        'comments_read'          => 0,
        'mapped'                 => 0,
        'tickets_created'        => 0,
        'followups_created'      => 0,
        'attachments_detected'   => 0,
        'attachments_downloaded' => 0,
        'attachments_failed'     => 0,
        'documents_linked'       => 0,
        'time_api_ms'            => 0,
        'time_dedupe_ms'         => 0,
        'time_map_ms'            => 0,
        'time_ticket_create_ms'  => 0,
        'time_comments_ms'       => 0,
        'time_followups_ms'      => 0,
        'time_attachments_ms'    => 0,
    ];

    public bool $isDryRun = false;

    public function incStat(string $key, int $by = 1): void
    {
        $this->stats[$key] = ($this->stats[$key] ?? 0) + $by;
    }

    public function measureStat(string $key, callable $callback): mixed
    {
        $start = microtime(true);
        try {
            return $callback();
        } finally {
            $this->incStat($key, (int) round((microtime(true) - $start) * 1000));
        }
    }

    public function addCreated(array $incident, int $ticketsId): void
    {
        $this->created[] = [
            'number'     => (string) ($incident['number'] ?? $incident['id'] ?? ''),
            'name'       => mb_substr((string) ($incident['name'] ?? ''), 0, 80),
            'tickets_id' => $ticketsId,
        ];
    }

    public function addFailed(array $incident, string $reason): void
    {
        $this->failed[] = [
            'number' => (string) ($incident['number'] ?? $incident['id'] ?? ''),
            'name'   => mb_substr((string) ($incident['name'] ?? ''), 0, 80),
            'reason' => $reason,
        ];
    }

    public function addSkipped(array $incident): void
    {
        $this->skipped[] = (string) ($incident['number'] ?? $incident['id'] ?? '');
    }

    public function addPreflightRow(
        array $incident,
        string $status,
        array $warnings = [],
        string $reason = ''
    ): void {
        $this->preflightRows[] = [
            'source_id' => (string) ($incident['id'] ?? ''),
            'number'    => (string) ($incident['number'] ?? $incident['id'] ?? ''),
            'name'      => mb_substr((string) ($incident['name'] ?? ''), 0, 80),
            'status'    => $status,
            'warnings'  => array_values($warnings),
            'reason'    => $reason,
        ];

        $this->mappingQuality[$status] = ($this->mappingQuality[$status] ?? 0) + 1;
    }

    public function total(): int
    {
        return count($this->created) + count($this->failed) + count($this->skipped);
    }

    public function isFullSuccess(): bool
    {
        return count($this->failed) === 0;
    }
}

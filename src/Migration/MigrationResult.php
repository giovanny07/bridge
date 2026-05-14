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

    public bool $isDryRun = false;

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

    public function total(): int
    {
        return count($this->created) + count($this->failed) + count($this->skipped);
    }

    public function isFullSuccess(): bool
    {
        return count($this->failed) === 0;
    }
}

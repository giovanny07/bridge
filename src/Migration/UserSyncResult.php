<?php

namespace GlpiPlugin\Bridge\Migration;

class UserSyncResult
{
    public bool  $isDryRun = false;
    public array $created  = []; // [['sw_id', 'name', 'email', 'entity']]
    public array $updated  = [];
    public array $skipped  = [];
    public array $failed   = []; // [['sw_id', 'name', 'email', 'reason']]

    public function addCreated(array $user, string $entityName): void
    {
        $this->created[] = $this->row($user, $entityName);
    }

    public function addUpdated(array $user, string $entityName): void
    {
        $this->updated[] = $this->row($user, $entityName);
    }

    public function addSkipped(array $user): void
    {
        $this->skipped[] = [
            'sw_id' => $user['id']    ?? '',
            'email' => $user['email'] ?? '',
        ];
    }

    public function addFailed(array $user, string $reason): void
    {
        $this->failed[] = array_merge($this->row($user, ''), ['reason' => $reason]);
    }

    public function total(): int
    {
        return count($this->created) + count($this->updated) + count($this->skipped) + count($this->failed);
    }

    private function row(array $user, string $entityName): array
    {
        return [
            'sw_id'  => $user['id']    ?? '',
            'name'   => $user['name']  ?? '',
            'email'  => $user['email'] ?? '',
            'entity' => $entityName,
        ];
    }
}

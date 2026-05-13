<?php

namespace GlpiPlugin\Bridge\Migration;

/**
 * Result of mapping a single Samanage incident to a GLPI ticket input array.
 *
 * 'ticket'   — ready to pass to Ticket::add() (all GLPI IDs resolved)
 * 'warnings' — human-readable list of fields that fell back to defaults
 * 'status'   — 'ok' | 'partial' | 'unresolved'
 *               ok         = all fields resolved without fallback
 *               partial    = one or more fields used fallback (still creatable)
 *               unresolved = critical field missing even after fallback
 */
readonly class MappedIncident
{
    public string $status;

    public function __construct(
        public array  $ticket,
        public array  $warnings,
        public array  $original,
        public array  $followups = [],
    ) {
        if ($warnings === []) {
            $this->status = 'ok';
        } elseif (($ticket['entities_id'] ?? 0) > 0) {
            $this->status = 'partial';
        } else {
            $this->status = 'unresolved';
        }
    }

    public function isOk(): bool
    {
        return $this->status === 'ok';
    }

    public function isCreatable(): bool
    {
        return $this->status !== 'unresolved';
    }
}

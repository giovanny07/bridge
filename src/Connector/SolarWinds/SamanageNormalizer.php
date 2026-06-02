<?php

namespace GlpiPlugin\Bridge\Connector\SolarWinds;

use GlpiPlugin\Bridge\Contract\NormalizerInterface;

/**
 * Maps SolarWinds Service Desk (Samanage) field values to GLPI equivalents.
 *
 * State/priority vocabulary confirmed on servicios.daycohost.com (2026-05-13):
 *   states    → En Proceso | Closed | Pendiente Acción Cliente
 *               Pending Assignment | Solucionado
 *   priorities → High | Medium | Low | Critical
 *   origins   → api | external | web
 */
class SamanageNormalizer implements NormalizerInterface
{
    // GLPI Ticket status values
    public const GLPI_STATUS_NEW      = 1;
    public const GLPI_STATUS_ASSIGNED = 2;
    public const GLPI_STATUS_PLANNED  = 3;
    public const GLPI_STATUS_PENDING  = 4;
    public const GLPI_STATUS_SOLVED   = 5;
    public const GLPI_STATUS_CLOSED   = 6;

    // GLPI priority values
    public const GLPI_PRIORITY_VERY_LOW  = 1;
    public const GLPI_PRIORITY_LOW       = 2;
    public const GLPI_PRIORITY_MEDIUM    = 3;
    public const GLPI_PRIORITY_HIGH      = 4;
    public const GLPI_PRIORITY_VERY_HIGH = 5;
    public const GLPI_PRIORITY_MAJOR     = 6;

    // GLPI RequestType IDs
    public const GLPI_ORIGIN_HELPDESK = 1;
    public const GLPI_ORIGIN_OTHER    = 6;
    public const GLPI_ORIGIN_EMAIL    = 7;

    private const STATE_MAP = [
        'Pending Assignment'       => self::GLPI_STATUS_NEW,
        'En Proceso'               => self::GLPI_STATUS_ASSIGNED,
        'Pendiente Acción Cliente' => self::GLPI_STATUS_PENDING,
        'Solucionado'              => self::GLPI_STATUS_SOLVED,
        'Closed'                   => self::GLPI_STATUS_CLOSED,
        'Gestión Proveedor'        => self::GLPI_STATUS_ASSIGNED,
        // English equivalents for other Samanage instances
        'New'                      => self::GLPI_STATUS_NEW,
        'Assigned'                 => self::GLPI_STATUS_ASSIGNED,
        'Waiting for Customer'     => self::GLPI_STATUS_PENDING,
        'Resolved'                 => self::GLPI_STATUS_SOLVED,
    ];

    private const PRIORITY_MAP = [
        'Low'      => self::GLPI_PRIORITY_LOW,
        'Medium'   => self::GLPI_PRIORITY_MEDIUM,
        'High'     => self::GLPI_PRIORITY_HIGH,
        'Critical' => self::GLPI_PRIORITY_VERY_HIGH,
    ];

    private const ORIGIN_MAP = [
        'web'      => self::GLPI_ORIGIN_HELPDESK,
        'email'    => self::GLPI_ORIGIN_EMAIL,
        'api'      => self::GLPI_ORIGIN_OTHER,
        'external' => self::GLPI_ORIGIN_OTHER,
    ];

    // ------------------------------------------------------------------ //
    // NormalizerInterface
    // ------------------------------------------------------------------ //

    public function systemType(): string
    {
        return 'solarwinds';
    }

    public function mapState(string $state): int
    {
        return self::STATE_MAP[$state] ?? self::GLPI_STATUS_NEW;
    }

    public function mapPriority(string $priority): int
    {
        return self::PRIORITY_MAP[$priority] ?? self::GLPI_PRIORITY_MEDIUM;
    }

    public function mapOrigin(string $origin): int
    {
        return self::ORIGIN_MAP[strtolower($origin)] ?? self::GLPI_ORIGIN_OTHER;
    }

    public function parseDate(?string $date): ?string
    {
        if ($date === null || $date === '') {
            return null;
        }
        try {
            // Return the datetime in the server's local timezone so GLPI stores
            // and displays dates that match what SolarWinds shows to the user.
            // Converting to UTC would cause a 4-hour offset on Venezuela servers.
            return (new \DateTimeImmutable($date))
                ->setTimezone(new \DateTimeZone(date_default_timezone_get()))
                ->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Comment structure confirmed on servicios.daycohost.com (2026-05-13):
     *   id, body (HTML), user.email, user.name, created_at, is_private,
     *   attachments[], inline_attachments[], shared_attachments[]
     */
    public function commentToFollowup(array $comment): array
    {
        return [
            'content'              => $this->stripLinks((string) ($comment['body'] ?? '')),
            'date'                 => $this->parseDate($comment['created_at'] ?? null),
            'is_private'           => (bool) ($comment['is_private'] ?? false),
            '_users_id'            => null, // resolved by IncidentMapper via GlpiResolver
            '_author_email'        => (string) ($comment['user']['email'] ?? ''),
            '_author_name'         => (string) ($comment['user']['name']  ?? ''),
            '_source_id'           => $comment['id'] ?? null,
            // Attachments preserved so MigrationEngine can download them
            '_attachments'         => $comment['attachments']        ?? [],
            '_inline_attachments'  => $comment['inline_attachments'] ?? [],
            '_shared_attachments'  => $comment['shared_attachments'] ?? [],
        ];
    }

    public function extractSolution(array $incident, array $comments): ?array
    {
        $state    = (string) ($incident['state'] ?? '');
        $isSolved = in_array($state, ['Solucionado', 'Closed', 'Resolved', 'Cerrado', 'Finalizado', 'Completed'], true);

        if (!$isSolved) {
            return null;
        }

        // 1. resolution_description — authoritative solution text written by a technician
        $desc = trim(strip_tags((string) ($incident['resolution_description'] ?? '')));
        if ($desc !== '') {
            return [
                'content'          => (string) $incident['resolution_description'],
                'date'             => $this->parseDate($incident['updated_at'] ?? null),
                '_author_email'    => (string) ($incident['resolved_by']['email'] ?? ''),
                '_users_id'        => null,
                '_skip_comment_id' => null,
            ];
        }

        // 2. resolution_code — brief label set by the technician (e.g. "Alarma Mitigada")
        $code = trim((string) ($incident['resolution_code'] ?? ''));
        if ($code !== '') {
            return [
                'content'          => $code,
                'date'             => $this->parseDate($incident['updated_at'] ?? null),
                '_author_email'    => '',
                '_users_id'        => null,
                '_skip_comment_id' => null,
            ];
        }

        // 3. Auto-closed alerts (Zabbix, API) have no explicit resolution.
        // Create a minimal solution using the state name so the GLPI timeline
        // is consistent — a closed ticket without any ITILSolution looks broken.
        return [
            'content'          => $state,
            'date'             => $this->parseDate($incident['updated_at'] ?? null),
            '_author_email'    => '',
            '_users_id'        => null,
            '_skip_comment_id' => null,
        ];
    }

    public function incidentToTicket(array $incident): array
    {
        $state = (string) ($incident['state'] ?? '');

        $number = (string) ($incident['number'] ?? '');
        $title  = (string) ($incident['name'] ?? '');
        if ($number !== '') {
            $title = "[ SD #{$number} ] {$title}";
        }

        return [
            'name'            => $title,
            'content'         => (string) ($incident['description_no_html'] ?? $incident['description'] ?? ''),
            'type'            => ($incident['is_service_request'] ?? false) ? 2 : 1, // 1=Incident 2=Service Request
            'status'          => $this->mapState($state),
            'priority'        => $this->mapPriority((string) ($incident['priority'] ?? '')),
            'requesttypes_id' => $this->mapOrigin((string) ($incident['origin'] ?? '')),
            'date'            => $this->parseDate($incident['created_at'] ?? null),
            'solvedate'       => in_array($state, ['Solucionado', 'Closed', 'Resolved', 'Cerrado', 'Finalizado', 'Completed'], true)
                                    ? $this->parseDate($incident['updated_at'] ?? null)
                                    : null,
            'closedate'       => $state === 'Closed'
                                    ? $this->parseDate($incident['updated_at'] ?? null)
                                    : null,
            '_source_id'      => $incident['id'] ?? null,
            '_source_number'  => $incident['number'] ?? null,
            '_source_href'    => $incident['href'] ?? null,
            '_source_raw'     => $incident,
        ];
    }

    /**
     * Maps a SolarWinds Problem to a GLPI ITILProblem input array.
     * Shares the same status/priority/date logic as incidentToTicket().
     * Extra SW fields (root_cause, symptoms, workaround) go to dedicated
     * GLPI columns or a followup note.
     */
    public function problemToITIL(array $problem): array
    {
        $state  = (string) ($problem['state'] ?? '');
        $number = (string) ($problem['number'] ?? '');
        $title  = (string) ($problem['name']   ?? '');
        if ($number !== '') {
            $title = "[ SD #$number ] $title";
        }

        return [
            'name'           => $title,
            'content'        => (string) ($problem['description_no_html'] ?? $problem['description'] ?? ''),
            'causecontent'   => $this->stripLinks((string) ($problem['root_cause']   ?? '')),
            'symptomcontent' => $this->stripLinks((string) ($problem['symptoms']     ?? '')),
            'status'         => $this->mapState($state),
            'priority'       => $this->mapPriority((string) ($problem['priority'] ?? '')),
            'date'           => $this->parseDate($problem['created_at'] ?? null),
            'solvedate'      => in_array($state, ['Solucionado', 'Closed', 'Resolved', 'Cerrado', 'Finalizado', 'Completed'], true)
                                    ? $this->parseDate($problem['updated_at'] ?? null)
                                    : null,
            'closedate'      => $state === 'Closed'
                                    ? $this->parseDate($problem['updated_at'] ?? null)
                                    : null,
            '_source_id'     => $problem['id']     ?? null,
            '_source_number' => $problem['number'] ?? null,
            '_source_href'   => $problem['href']   ?? null,
            '_source_raw'    => $problem,
            // Workaround has no direct GLPI column — stored as private followup
            '_workaround'    => $this->stripLinks((string) ($problem['workaround'] ?? '')),
        ];
    }

    // GLPI ITILChange status values
    private const CHANGE_STATUS_MAP = [
        'Solicitado'   => 1,  // New (INCOMING)
        'Pre Aprobado' => 9,  // Evaluation
        'Aprobado'     => 7,  // Accepted
        'Iniciado'     => 2,  // Assigned
        'Revisado'     => 12, // Qualification
        'Post Mortem'  => 12, // Qualification (post-implementation review)
        'Finalizado'   => 6,  // Applied → Closed for migration (historical, no pending review)
        'Cerrado'      => 6,  // Closed
        'Rechazado'    => 13, // Refused
        'Cancelado'    => 14, // Cancelled
        'Expirado'     => 14, // Expired → Cancelled (no direct GLPI equivalent)
        // English equivalents
        'New'          => 1,
        'Approved'     => 7,
        'In Progress'  => 2,
        'Completed'    => 5,
        'Closed'       => 6,
        'Refused'      => 13,
        'Cancelled'    => 14,
    ];

    /**
     * Maps a SolarWinds Change to a GLPI ITILChange input array.
     */
    public function changeToITIL(array $change): array
    {
        $state  = (string) ($change['state']  ?? '');
        $number = (string) ($change['number'] ?? '');
        $title  = (string) ($change['name']   ?? '');
        if ($number !== '') {
            $title = "[ SD #$number ] $title";
        }

        $status = self::CHANGE_STATUS_MAP[$state] ?? 1;
        $isClosed = in_array($status, [5, 6], true);

        return [
            'name'                => $title,
            'content'             => (string) ($change['description_no_html'] ?? $change['description'] ?? ''),
            'rolloutplancontent'  => $this->stripLinks((string) ($change['change_plan']    ?? '')),
            'backoutplancontent'  => $this->stripLinks((string) ($change['rollback_plan']  ?? '')),
            'checklistcontent'    => $this->stripLinks((string) ($change['test_plan']      ?? '')),
            'status'              => $status,
            'priority'            => $this->mapPriority((string) ($change['priority'] ?? '')),
            'date'                => $this->parseDate($change['created_at']  ?? null),
            'solvedate'           => $isClosed ? $this->parseDate($change['updated_at'] ?? null) : null,
            'closedate'           => $status === 6 ? $this->parseDate($change['updated_at'] ?? null) : null,
            '_source_id'          => $change['id']     ?? null,
            '_source_number'      => $change['number'] ?? null,
            '_source_href'        => $change['href']   ?? null,
            '_source_raw'         => $change,
        ];
    }

    /**
     * Strips <a href> links from HTML, keeping the anchor text.
     * SolarWinds comments often embed links to other SD tickets
     * (e.g. /incidents/135126995) that would be dead links in GLPI.
     *
     * Before: <a href="https://servicios.daycohost.com/incidents/135126995">#10640</a>
     * After:  #10640
     */
    private function stripLinks(string $html): string
    {
        return (string) preg_replace('/<a\b[^>]*>(.*?)<\/a>/is', '$1', $html);
    }
}

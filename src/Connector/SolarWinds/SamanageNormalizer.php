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
            return (new \DateTimeImmutable($date))
                ->setTimezone(new \DateTimeZone('UTC'))
                ->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return null;
        }
    }

    public function incidentToTicket(array $incident): array
    {
        $state = (string) ($incident['state'] ?? '');

        return [
            'name'            => (string) ($incident['name'] ?? ''),
            'content'         => (string) ($incident['description_no_html'] ?? $incident['description'] ?? ''),
            'status'          => $this->mapState($state),
            'priority'        => $this->mapPriority((string) ($incident['priority'] ?? '')),
            'requesttypes_id' => $this->mapOrigin((string) ($incident['origin'] ?? '')),
            'date'            => $this->parseDate($incident['created_at'] ?? null),
            'solvedate'       => in_array($state, ['Solucionado', 'Closed', 'Resolved'], true)
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
}

<?php

namespace GlpiPlugin\Bridge\Normalizer;

/**
 * Maps Samanage/SolarWinds Service Desk field values to GLPI equivalents.
 *
 * All constants mirror GLPI core values so this class can be used without
 * importing any GLPI symbol (keeping it unit-testable standalone).
 *
 * API vocabulary discovered on 2026-05-13 against servicios.daycohost.com:
 *   states    → En Proceso | Closed | Pendiente Acción Cliente | Pending Assignment | Solucionado
 *   priorities → High | Medium | Low
 *   origins   → api | external | web
 */
class SamanageNormalizer
{
    // GLPI Ticket::STATUS_* equivalents
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

    /**
     * Known Samanage states → GLPI status.
     * Add entries here as new states are discovered in the instance.
     */
    private const STATE_MAP = [
        'Pending Assignment'       => self::GLPI_STATUS_NEW,
        'En Proceso'               => self::GLPI_STATUS_ASSIGNED,
        'Pendiente Acción Cliente' => self::GLPI_STATUS_PENDING,
        'Solucionado'              => self::GLPI_STATUS_SOLVED,
        'Closed'                   => self::GLPI_STATUS_CLOSED,
        // English equivalents (other instances may use these)
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

    public static function mapState(string $state): int
    {
        return self::STATE_MAP[$state] ?? self::GLPI_STATUS_NEW;
    }

    public static function mapPriority(string $priority): int
    {
        return self::PRIORITY_MAP[$priority] ?? self::GLPI_PRIORITY_MEDIUM;
    }

    public static function mapOrigin(string $origin): int
    {
        return self::ORIGIN_MAP[strtolower($origin)] ?? self::GLPI_ORIGIN_OTHER;
    }

    /**
     * Converts an ISO 8601 date string (e.g. "2026-05-13T16:40:26.000-04:00")
     * to a MySQL DATETIME string ("2026-05-13 20:40:26" in UTC).
     */
    public static function parseDate(?string $date): ?string
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

    /**
     * Maps a single Samanage incident array to a GLPI Ticket input array.
     *
     * The returned array is intentionally slim: only fields that have a direct
     * 1-to-1 mapping and that we have confirmed in the API. Fields that need
     * extra logic (categories, assets, custom fields) are returned under
     * _samanage_raw so callers can handle them without re-fetching.
     */
    public static function incidentToTicket(array $incident): array
    {
        return [
            'name'             => (string) ($incident['name'] ?? ''),
            'content'          => (string) ($incident['description_no_html'] ?? $incident['description'] ?? ''),
            'status'           => self::mapState((string) ($incident['state'] ?? '')),
            'priority'         => self::mapPriority((string) ($incident['priority'] ?? '')),
            'requesttypes_id'  => self::mapOrigin((string) ($incident['origin'] ?? '')),
            'date'             => self::parseDate($incident['created_at'] ?? null),
            'solvedate'        => in_array($incident['state'] ?? '', ['Solucionado', 'Closed', 'Resolved'], true)
                                    ? self::parseDate($incident['updated_at'] ?? null)
                                    : null,
            'closedate'        => ($incident['state'] ?? '') === 'Closed'
                                    ? self::parseDate($incident['updated_at'] ?? null)
                                    : null,
            '_samanage_id'     => $incident['id'] ?? null,
            '_samanage_number' => $incident['number'] ?? null,
            '_samanage_href'   => $incident['href'] ?? null,
            '_samanage_raw'    => $incident,
        ];
    }
}

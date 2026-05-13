<?php

namespace GlpiPlugin\Bridge\Contract;

/**
 * Contract for mapping source-system field values to GLPI equivalents.
 *
 * Each connector ships its own implementation so that state names, priority
 * labels and date formats are handled per-system without touching shared code.
 */
interface NormalizerInterface
{
    /** The system_type string this normalizer handles (e.g. 'solarwinds'). */
    public function systemType(): string;

    /**
     * Maps a single source incident to a partial GLPI ticket input array.
     * Must include at minimum: name, content, status, priority,
     * requesttypes_id, date, _samanage_id (or equivalent source ID key).
     */
    public function incidentToTicket(array $incident): array;

    /** Maps a source state string to a GLPI Ticket::STATUS_* integer. */
    public function mapState(string $state): int;

    /** Maps a source priority string to a GLPI priority integer (1–6). */
    public function mapPriority(string $priority): int;

    /** Maps a source origin string to a GLPI requesttypes_id integer. */
    public function mapOrigin(string $origin): int;

    /**
     * Converts a source date string (any timezone) to UTC MySQL DATETIME.
     * Returns null when the input is empty or unparseable.
     */
    public function parseDate(?string $date): ?string;

    /**
     * Maps a source comment to a partial GLPI ITILFollowup input array.
     * Must include: content, date, is_private, _author_email, _source_id.
     * _users_id is left null — resolved by IncidentMapper via GlpiResolver.
     */
    public function commentToFollowup(array $comment): array;
}

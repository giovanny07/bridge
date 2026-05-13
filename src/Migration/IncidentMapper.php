<?php

namespace GlpiPlugin\Bridge\Migration;

use GlpiPlugin\Bridge\Contract\NormalizerInterface;
use GlpiPlugin\Bridge\Resolver\GlpiResolver;

/**
 * Combines a NormalizerInterface (field value mapping) with GlpiResolver
 * (name → GLPI ID) to produce a complete GLPI ticket input array.
 *
 * System-agnostic: the actual field mapping is delegated to the normalizer
 * so this class works unchanged when a new source system is added.
 */
class IncidentMapper
{
    public function __construct(
        private readonly GlpiResolver        $resolver,
        private readonly NormalizerInterface $normalizer,
        private readonly int                 $fallbackEntityId = 0,
        private readonly int                 $fallbackGroupId  = 0,
    ) {}

    public function map(array $incident): MappedIncident
    {
        $warnings = [];

        // ── Entity ──────────────────────────────────────────────────────
        $siteName = (string) ($incident['site']['name'] ?? '');
        $entityId = null;

        if ($siteName !== '') {
            $entityId = $this->resolver->resolveEntity($siteName);
            if ($entityId === null) {
                $warnings[] = "Entity not found: «{$siteName}» → fallback used";
            }
        }

        $entityId = $entityId ?? ($this->fallbackEntityId ?: null);

        // ── Category ────────────────────────────────────────────────────
        $catName  = (string) ($incident['category']['name']    ?? '');
        $subName  = (string) ($incident['subcategory']['name'] ?? '');
        $categoryId = null;

        if ($catName !== '') {
            $categoryId = $this->resolver->resolveCategory($catName, $subName);
            if ($categoryId === null) {
                $label = $subName !== '' ? "{$catName} / {$subName}" : $catName;
                $warnings[] = "Category not found: «{$label}»";
            }
        }

        // ── Assignee: group or user ──────────────────────────────────────
        $assignee    = $incident['assignee'] ?? [];
        $isUser      = (bool) ($assignee['is_user'] ?? true);
        $assigneeId  = null;
        $groupId     = null;

        if ($isUser) {
            $email      = (string) ($assignee['email'] ?? '');
            $assigneeId = $this->resolver->resolveUserByEmail($email);
            if ($assigneeId === null && $email !== '') {
                $warnings[] = "Assignee user not found: «{$email}»";
            }
            $groupId = $this->fallbackGroupId ?: null;
        } else {
            $groupName = (string) ($assignee['name'] ?? '');
            $groupId   = $this->resolver->resolveGroup($groupName);
            if ($groupId === null) {
                $warnings[] = "Assignee group not found: «{$groupName}» → fallback used";
                $groupId = $this->fallbackGroupId ?: null;
            }
        }

        // ── Requester ────────────────────────────────────────────────────
        $requesterEmail = (string) ($incident['requester']['email'] ?? '');
        $requesterId    = $this->resolver->resolveUserByEmail($requesterEmail);

        if ($requesterId === null && $requesterEmail !== '') {
            $warnings[] = "Requester not found: «{$requesterEmail}»";
        }

        // ── Assemble ticket input ────────────────────────────────────────
        $base   = $this->normalizer->incidentToTicket($incident);
        $ticket = array_merge($base, [
            'entities_id'          => $entityId ?? 0,
            'itilcategories_id'    => $categoryId ?? 0,
            '_groups_id_assign'    => $groupId,
            '_users_id_assign'     => $assigneeId,
            '_users_id_requester'  => $requesterId,
        ]);

        return new MappedIncident($ticket, $warnings, $incident);
    }
}

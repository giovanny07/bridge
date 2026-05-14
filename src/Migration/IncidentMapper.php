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
        private readonly int                 $fallbackEntityId  = 0,
        private readonly int                 $fallbackGroupId   = 0,
        private readonly int                 $fallbackRequesterId = 0,
    ) {}

    /**
     * @param array $incident  Raw incident from the source system.
     * @param array $comments  Raw comments (optional). Pass [] for dry-run
     *                         to avoid extra API calls; pass the real list
     *                         when doing the actual migration.
     */
    public function map(array $incident, array $comments = []): MappedIncident
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
            if ($this->fallbackRequesterId > 0) {
                $requesterId = $this->fallbackRequesterId;
                $warnings[] = "Requester not found: «{$requesterEmail}» → fallback user used";
            } else {
                $warnings[] = "Requester not found: «{$requesterEmail}»";
            }
        }

        // ── Assemble ticket input ────────────────────────────────────────
        $base   = $this->normalizer->incidentToTicket($incident);
        $ticket = array_merge($base, [
            'entities_id'         => $entityId ?? 0,
            'itilcategories_id'   => $categoryId ?? 0,
            // Pass 0 (not null) for unresolved actors: null triggers a GLPI
            // E_USER_WARNING that causes the session admin to be used as fallback.
            // GLPI explicitly skips items_id=0 as "empty value".
            '_groups_id_assign'   => $groupId     ?? 0,
            '_users_id_assign'    => $assigneeId  ?? 0,
            '_users_id_requester' => $requesterId ?? 0,
        ]);

        // ── Extract solution (last comment or resolution_description) ────
        $solution        = null;
        $skipCommentId   = null;

        if (!empty($comments)) {
            $rawSolution = $this->normalizer->extractSolution($incident, $comments);
            if ($rawSolution !== null) {
                $skipCommentId = $rawSolution['_skip_comment_id'];
                $solutionUser  = $this->resolver->resolveUserByEmail($rawSolution['_author_email']);
                $solution = array_merge($rawSolution, ['_users_id' => $solutionUser]);
            }
        }

        // ── Map remaining comments → followups (skip the solution comment) ─
        // Also skip any comment dated after the solution — those are admin close
        // notifications that would appear after the solution in the timeline.
        $solutionTs = ($solution !== null && !empty($solution['date']))
            ? strtotime($solution['date'])
            : null;

        $followups = [];
        foreach ($comments as $comment) {
            if ($skipCommentId !== null && ($comment['id'] ?? null) == $skipCommentId) {
                continue;
            }
            if ($solutionTs !== null) {
                $commentTs = strtotime((string) ($comment['created_at'] ?? ''));
                if ($commentTs !== false && $commentTs > $solutionTs) {
                    continue; // Post-solution admin note — skip
                }
            }
            $followup    = $this->normalizer->commentToFollowup($comment);
            $authorId    = $this->resolver->resolveUserByEmail($followup['_author_email']);
            $followups[] = array_merge($followup, ['_users_id' => $authorId]);
        }

        return new MappedIncident($ticket, $warnings, $incident, $followups, $solution);
    }
}

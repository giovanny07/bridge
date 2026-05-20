<?php

namespace GlpiPlugin\Bridge\Migration;

use GlpiPlugin\Bridge\Contract\ConnectorInterface;
use GlpiPlugin\Bridge\Resolver\GlpiResolver;

/**
 * Syncs users from a source system into GLPI.
 *
 * Only creates user + email records — no profile assignment.
 * The resolver can then find these users by email when migrating tickets.
 *
 * Options:
 *   source_ids      string  Comma-separated SW user IDs (overrides pagination)
 *   limit           int     Max users per run when paginating (default 200)
 *   start_page      int     First API page (default 1)
 *   role_filter     string  Only sync users with this role name, empty = all
 *   skip_disabled   bool    Skip disabled/inactive SW users (default true)
 *   update_existing bool    Update name/phone on already-existing users (default false)
 *   dry_run         bool    Preview only, nothing written to GLPI (default false)
 */
class UserSyncer
{
    private const PER_PAGE = 100;

    public function __construct(
        private readonly ConnectorInterface $connector,
        private readonly GlpiResolver       $resolver,
        private readonly int                $fallbackEntityId = 0,
    ) {}

    public function run(array $options): UserSyncResult
    {
        $limit          = max(1, (int) ($options['limit']        ?? 200));
        $startPage      = max(1, (int) ($options['start_page']   ?? 1));
        $roleFilter     = trim((string) ($options['role_filter'] ?? ''));
        $skipDisabled   = (bool) ($options['skip_disabled']      ?? true);
        $updateExisting = (bool) ($options['update_existing']    ?? false);
        $isDryRun       = (bool) ($options['dry_run']            ?? false);
        $rawIds         = trim((string) ($options['source_ids']  ?? ''));
        $sourceIds      = $rawIds !== '' ? array_values(array_filter(array_map('trim', explode(',', $rawIds)))) : [];

        $result           = new UserSyncResult();
        $result->isDryRun = $isDryRun;

        if (!empty($sourceIds)) {
            foreach ($sourceIds as $rawId) {
                try {
                    $user = $this->connector->getUser((int) $rawId);
                } catch (\Throwable $e) {
                    $result->addFailed(['id' => $rawId, 'name' => "ID $rawId", 'email' => ''], $e->getMessage());
                    continue;
                }
                $this->processUser($user, $skipDisabled, $roleFilter, $updateExisting, $isDryRun, $result);
            }
            return $result;
        }

        $page = $startPage;
        while ($result->total() < $limit) {
            $batch = $this->connector->listUsers([], $page, self::PER_PAGE);
            if (empty($batch['records'])) {
                break;
            }
            foreach ($batch['records'] as $user) {
                if ($result->total() >= $limit) {
                    break;
                }
                $this->processUser($user, $skipDisabled, $roleFilter, $updateExisting, $isDryRun, $result);
            }
            if ($batch['count'] < self::PER_PAGE) {
                break;
            }
            $page++;
        }

        return $result;
    }

    // ------------------------------------------------------------------ //

    private function processUser(
        array          $user,
        bool           $skipDisabled,
        string         $roleFilter,
        bool           $updateExisting,
        bool           $isDryRun,
        UserSyncResult $result
    ): void {
        $email = trim((string) ($user['email'] ?? ''));
        if ($email === '') {
            $result->addFailed($user, 'No email address');
            return;
        }

        if ($skipDisabled && ($user['disabled'] ?? false)) {
            $result->addSkipped($user);
            return;
        }

        if ($roleFilter !== '' && strtolower($user['role']['name'] ?? '') !== strtolower($roleFilter)) {
            $result->addSkipped($user);
            return;
        }

        // Resolve entity from user's site
        $siteName   = (string) ($user['site']['name'] ?? '');
        $entityId   = $siteName !== '' ? $this->resolver->resolveEntity($siteName) : null;
        $entityId   = $entityId ?? ($this->fallbackEntityId ?: 0);
        $entityName = $siteName !== '' ? $siteName : '';

        if ($isDryRun) {
            $existingId = $this->findUserByEmail($email);
            if ($existingId !== null) {
                $result->addSkipped($user);
            } else {
                $result->addCreated($user, $entityName);
            }
            return;
        }

        $existingId = $this->findUserByEmail($email);

        if ($existingId !== null) {
            if ($updateExisting) {
                $this->updateUser($existingId, $user, $entityId);
                $result->addUpdated($user, $entityName);
            } else {
                $result->addSkipped($user);
            }
            return;
        }

        try {
            $this->createUser($user, $email, $entityId);
            $result->addCreated($user, $entityName);
        } catch (\Throwable $e) {
            $result->addFailed($user, $e->getMessage());
        }
    }

    // ------------------------------------------------------------------ //
    // GLPI record operations (direct DB — bypasses ORM overhead)
    // ------------------------------------------------------------------ //

    private function findUserByEmail(string $email): ?int
    {
        global $DB;
        foreach ($DB->request([
            'SELECT' => ['users_id'],
            'FROM'   => 'glpi_useremails',
            'WHERE'  => ['email' => strtolower(trim($email))],
            'LIMIT'  => 1,
        ]) as $row) {
            return (int) $row['users_id'];
        }
        return null;
    }

    private function createUser(array $swUser, string $email, int $entityId): void
    {
        global $DB;

        [$firstname, $realname] = $this->splitName((string) ($swUser['name'] ?? ''));

        $DB->insert('glpi_users', [
            'name'          => strtolower($email),
            'firstname'     => $firstname,
            'realname'      => $realname,
            'phone'         => (string) ($swUser['phone'] ?? ''),
            'entities_id'   => $entityId,
            'is_active'     => ($swUser['disabled'] ?? false) ? 0 : 1,
            'is_deleted'    => 0,
            'password'      => '',
            'date_creation' => date('Y-m-d H:i:s'),
            'date_mod'      => date('Y-m-d H:i:s'),
        ]);

        $userId = (int) $DB->insertId();
        if ($userId <= 0) {
            throw new \RuntimeException("Failed to insert user $email");
        }

        $DB->insert('glpi_useremails', [
            'users_id'   => $userId,
            'is_default' => 1,
            'is_dynamic' => 0,
            'email'      => strtolower(trim($email)),
        ]);
    }

    private function updateUser(int $userId, array $swUser, int $entityId): void
    {
        global $DB;

        [$firstname, $realname] = $this->splitName((string) ($swUser['name'] ?? ''));

        $DB->update('glpi_users', [
            'firstname'   => $firstname,
            'realname'    => $realname,
            'phone'       => (string) ($swUser['phone'] ?? ''),
            'entities_id' => $entityId,
            'is_active'   => ($swUser['disabled'] ?? false) ? 0 : 1,
            'date_mod'    => date('Y-m-d H:i:s'),
        ], ['id' => $userId]);
    }

    private function splitName(string $fullName): array
    {
        $parts     = explode(' ', trim($fullName), 2);
        $firstname = $parts[0] ?? '';
        $realname  = $parts[1] ?? '';
        return [$firstname, $realname];
    }
}

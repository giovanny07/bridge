<?php

namespace GlpiPlugin\Bridge\Resolver;

/**
 * Resolves Samanage field values to GLPI IDs by name matching.
 *
 * All lookups are lazy-loaded and cached in memory for the lifetime of the
 * request. Matching is case-insensitive and accent-agnostic so that minor
 * spelling differences between systems don't block resolution.
 *
 * Matching strategy (first hit wins):
 *   1. Exact normalized match
 *   2. Match after stripping common legal suffixes (C.A., S.A., …)
 *   3. Returns null — caller decides the fallback
 */
class GlpiResolver
{
    /** @var array<string,int> normalized_name => id */
    private array $entities   = [];
    private array $categories = [];
    private array $groups     = [];
    /** @var array<string,int> email_lower => users_id */
    private array $users      = [];

    private bool $entitiesLoaded   = false;
    private bool $categoriesLoaded = false;
    private bool $groupsLoaded     = false;
    private bool $usersLoaded      = false;

    public function __construct(private readonly object $db) {}

    public static function create(): self
    {
        global $DB;
        return new self($DB);
    }

    // ------------------------------------------------------------------ //
    // Public API
    // ------------------------------------------------------------------ //

    public function resolveEntity(string $name): ?int
    {
        $this->loadEntities();
        return $this->lookup($this->entities, $name);
    }

    /**
     * Resolves a category by subcategory name first, then parent category name.
     * Uses the more specific match when both are provided.
     */
    public function resolveCategory(string $parentName, string $childName = ''): ?int
    {
        $this->loadCategories();

        if ($childName !== '') {
            $hit = $this->lookup($this->categories, $childName);
            if ($hit !== null) {
                return $hit;
            }
        }

        return $this->lookup($this->categories, $parentName);
    }

    public function resolveGroup(string $name): ?int
    {
        $this->loadGroups();
        return $this->lookup($this->groups, $name);
    }

    public function resolveUserByEmail(string $email): ?int
    {
        if ($email === '') {
            return null;
        }
        $this->loadUsers();
        return $this->users[strtolower(trim($email))] ?? null;
    }

    // ------------------------------------------------------------------ //
    // Loaders
    // ------------------------------------------------------------------ //

    private function loadEntities(): void
    {
        if ($this->entitiesLoaded) {
            return;
        }
        foreach ($this->db->request(['FROM' => 'glpi_entities']) as $row) {
            $key = $this->normalize((string) $row['name']);
            $this->entities[$key] = (int) $row['id'];
        }
        $this->entitiesLoaded = true;
    }

    private function loadCategories(): void
    {
        if ($this->categoriesLoaded) {
            return;
        }
        foreach ($this->db->request(['FROM' => 'glpi_itilcategories']) as $row) {
            $key = $this->normalize((string) $row['name']);
            $this->categories[$key] = (int) $row['id'];
        }
        $this->categoriesLoaded = true;
    }

    private function loadGroups(): void
    {
        if ($this->groupsLoaded) {
            return;
        }
        foreach ($this->db->request(['FROM' => 'glpi_groups']) as $row) {
            $key = $this->normalize((string) $row['name']);
            $this->groups[$key] = (int) $row['id'];
        }
        $this->groupsLoaded = true;
    }

    private function loadUsers(): void
    {
        if ($this->usersLoaded) {
            return;
        }
        // Emails live in glpi_useremails; join to get users_id
        foreach ($this->db->request([
            'SELECT'     => ['glpi_users.id', 'glpi_useremails.email'],
            'FROM'       => 'glpi_useremails',
            'INNER JOIN' => [
                'glpi_users' => [
                    'FKEY' => ['glpi_users' => 'id', 'glpi_useremails' => 'users_id'],
                ],
            ],
            'WHERE'      => ['glpi_users.is_deleted' => 0],
        ]) as $row) {
            $email = strtolower(trim((string) ($row['email'] ?? '')));
            if ($email !== '') {
                $this->users[$email] = (int) $row['id'];
            }
        }
        $this->usersLoaded = true;
    }

    // ------------------------------------------------------------------ //
    // Helpers
    // ------------------------------------------------------------------ //

    private function lookup(array $index, string $name): ?int
    {
        // Pass 1: exact normalized match
        $key = $this->normalize($name);
        if (isset($index[$key])) {
            return $index[$key];
        }

        // Pass 2: strip common Venezuelan legal suffixes and retry
        $stripped = $this->normalize($this->stripLegalSuffix($name));
        foreach ($index as $indexKey => $id) {
            if ($this->stripLegalSuffix($indexKey) === $stripped) {
                return $id;
            }
        }

        // Pass 3: strip trailing parenthetical from index names and compare.
        // GLPI: "Pluxee Beneficios e Incentivos, C.A. (Sodexo)"  → stripped: "pluxee beneficios e incentivos"
        // SW:   "Pluxee Beneficios e Incentivos, C.A."            → $stripped: "pluxee beneficios e incentivos"
        foreach ($index as $indexKey => $id) {
            // Strip parenthetical first, then legal suffix:
            // "pluxee..., c.a. (sodexo)" → "pluxee..., c.a." → "pluxee..."
            $indexStripped3 = $this->stripLegalSuffix($this->stripParenthetical($indexKey));
            if ($indexStripped3 === $stripped) {
                return $id;
            }
        }

        return null;
    }

    /**
     * Removes trailing parenthetical content, e.g. "Empresa XYZ (Antigua Razón)" → "Empresa XYZ".
     * Operates on an already-normalized (ASCII, lowercased) string.
     */
    private function stripParenthetical(string $normalized): string
    {
        return trim((string) preg_replace('/\s*\([^)]*\)\s*$/', '', $normalized));
    }

    /**
     * Lowercase + transliterate accents + collapse whitespace.
     * Keeps punctuation so "C.A." is not confused with "CA".
     */
    public function normalize(string $s): string
    {
        $s = mb_strtolower(trim($s), 'UTF-8');
        // Replace accented chars: á→a, é→e, etc.
        $s = (string) iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
        // Strip quotation marks: "Molanca" → Molanca
        $s = (string) str_replace(['"', "'", "\u{201C}", "\u{201D}", "\u{2018}", "\u{2019}"], '', $s);
        // Collapse "C. A." / "S. A." (space inside abbreviated suffix) → "c.a." / "s.a."
        $s = (string) preg_replace('/\b([a-z])\.\s+([a-z])\./i', '$1.$2.', $s);
        $s = (string) preg_replace('/\s+/', ' ', $s);
        return trim($s);
    }

    /**
     * Removes trailing legal-entity suffixes common in Venezuelan company names.
     * Handles: ", C.A." / ". C.A." / " C A" / " CA" / " C.A" (all variants).
     * Operates on an already-normalized (ASCII) string.
     */
    private function stripLegalSuffix(string $normalized): string
    {
        $suffixes = [
            // S.A.C.A. / SACA
            '[,.]?\s*s\.?\s*a\.?\s*c\.?\s*a\.?$',
            // C.A. / CA / C A (with or without dots/spaces between letters)
            '[,.]?\s*c\.?\s*a\.?$',
            // S.A. / SA
            '[,.]?\s*s\.?\s*a\.?$',
            // S.R.L. / SRL
            '[,.]?\s*s\.?\s*r\.?\s*l\.?$',
            // N.V.
            '[,.]?\s*n\.?\s*v\.?$',
            // Inc.
            '[,.]?\s*inc\.?$',
        ];
        foreach ($suffixes as $pattern) {
            $result = (string) preg_replace('/' . $pattern . '/i', '', $normalized);
            if ($result !== $normalized) {
                return trim(rtrim(trim($result), ',.'));
            }
        }
        return $normalized;
    }
}

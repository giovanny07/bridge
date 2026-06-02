<?php

namespace GlpiPlugin\Bridge\Tests\Units;

use GlpiPlugin\Bridge\Resolver\GlpiResolver;
use PHPUnit\Framework\TestCase;

class GlpiResolverTest extends TestCase
{
    private function makeDb(array $tables): object
    {
        return new class($tables) {
            public function __construct(private array $tables) {}
            public function request(array $criteria): array
            {
                $from = $criteria['FROM'] ?? '';
                // Handle joins (users + useremails)
                if (isset($criteria['INNER JOIN'])) {
                    return $this->tables[$from . '_joined'] ?? [];
                }
                return $this->tables[$from] ?? [];
            }
            public function fieldExists(): bool { return true; }
        };
    }

    private function makeResolver(array $tables): GlpiResolver
    {
        return new GlpiResolver($this->makeDb($tables));
    }

    // ------------------------------------------------------------------ //
    // resolveEntity
    // ------------------------------------------------------------------ //

    public function testResolveEntityExactMatch(): void
    {
        $resolver = $this->makeResolver([
            'glpi_entities' => [
                ['id' => 30, 'name' => 'Acumuladores Duncan, C.A.'],
            ],
        ]);

        $this->assertSame(30, $resolver->resolveEntity('Acumuladores Duncan, C.A.'));
    }

    public function testResolveEntityCaseInsensitive(): void
    {
        $resolver = $this->makeResolver([
            'glpi_entities' => [['id' => 30, 'name' => 'Acumuladores Duncan, C.A.']],
        ]);

        $this->assertSame(30, $resolver->resolveEntity('ACUMULADORES DUNCAN, C.A.'));
    }

    public function testResolveEntityAccentInsensitive(): void
    {
        $resolver = $this->makeResolver([
            'glpi_entities' => [['id' => 34, 'name' => 'Alcaldía del Municipio Sucre']],
        ]);

        $this->assertSame(34, $resolver->resolveEntity('Alcaldia del Municipio Sucre'));
    }

    public function testResolveEntitySuffixFallback(): void
    {
        // GLPI has "Banco Plaza" but SolarWinds has "Banco Plaza, C.A., Banco Universal"
        $resolver = $this->makeResolver([
            'glpi_entities' => [['id' => 251, 'name' => 'Banco Plaza, C.A., Banco Universal']],
        ]);

        $this->assertSame(251, $resolver->resolveEntity('Banco Plaza, C.A., Banco Universal'));
    }

    public function testResolveEntityParentheticalFallback(): void
    {
        // GLPI has "Pluxee Beneficios e Incentivos, C.A. (Sodexo)"
        // SolarWinds has "Pluxee Beneficios e Incentivos, C.A." (no parenthetical)
        $resolver = $this->makeResolver([
            'glpi_entities' => [['id' => 290, 'name' => 'Pluxee Beneficios e Incentivos, C.A. (Sodexo)']],
        ]);

        $this->assertSame(290, $resolver->resolveEntity('Pluxee Beneficios e Incentivos, C.A.'));
    }

    public function testResolveEntitySpacedAbbreviation(): void
    {
        // SW: "MDS Telecom, C. A."  ↔  GLPI: "MDS Telecom, C.A."
        $resolver = $this->makeResolver([
            'glpi_entities' => [['id' => 10, 'name' => 'MDS Telecom, C.A.']],
        ]);
        $this->assertSame(10, $resolver->resolveEntity('MDS Telecom, C. A.'));
    }

    public function testResolveEntityMissingTrailingPeriod(): void
    {
        // SW: "Oha Technology, C.A"  ↔  GLPI: "Oha Technology, C.A."
        $resolver = $this->makeResolver([
            'glpi_entities' => [['id' => 11, 'name' => 'Oha Technology, C.A.']],
        ]);
        $this->assertSame(11, $resolver->resolveEntity('Oha Technology, C.A'));
    }

    public function testResolveEntityNoPunctuation(): void
    {
        // SW: "Filotor C A"  ↔  GLPI: "Filotor C.A."
        $resolver = $this->makeResolver([
            'glpi_entities' => [['id' => 12, 'name' => 'Filotor, C.A.']],
        ]);
        $this->assertSame(12, $resolver->resolveEntity('Filotor C A'));
    }

    public function testResolveEntityPeriodSeparatorBeforeSuffix(): void
    {
        // SW: "Bangente. C.A."  ↔  GLPI: "Bangente, C.A."
        $resolver = $this->makeResolver([
            'glpi_entities' => [['id' => 13, 'name' => 'Banco de la Gente Emprendedora (Bangente), C.A.']],
        ]);
        $this->assertSame(13, $resolver->resolveEntity('Banco de la Gente Emprendedora (Bangente). C.A.'));
    }

    public function testResolveEntityStripsQuotes(): void
    {
        // SW: 'Moldeados Andinos, C.A "Molanca"'  ↔  GLPI: 'Moldeados Andinos, C.A Molanca'
        $resolver = $this->makeResolver([
            'glpi_entities' => [['id' => 258, 'name' => 'Moldeados Andinos, C.A Molanca']],
        ]);
        $this->assertSame(258, $resolver->resolveEntity('Moldeados Andinos, C.A "Molanca"'));
    }

    public function testResolveEntityReturnsNullWhenNotFound(): void
    {
        $resolver = $this->makeResolver(['glpi_entities' => []]);

        $this->assertNull($resolver->resolveEntity('Empresa Inexistente'));
    }

    // ------------------------------------------------------------------ //
    // resolveCategory
    // ------------------------------------------------------------------ //

    public function testResolveCategoryByChildName(): void
    {
        $resolver = $this->makeResolver([
            'glpi_itilcategories' => [
                ['id' => 10, 'name' => 'Falla de Conexión', 'is_deleted' => 0],
                ['id' => 7,  'name' => 'Acceso a Internet', 'is_deleted' => 0],
            ],
        ]);

        // Child name match takes priority over parent
        $this->assertSame(10, $resolver->resolveCategory('Acceso a Internet', 'Falla de Conexión'));
    }

    public function testResolveCategoryFallsBackToParent(): void
    {
        $resolver = $this->makeResolver([
            'glpi_itilcategories' => [
                ['id' => 7, 'name' => 'Acceso a Internet', 'is_deleted' => 0],
            ],
        ]);

        $this->assertSame(7, $resolver->resolveCategory('Acceso a Internet', 'Subcategoria Inexistente'));
    }

    public function testResolveCategoryReturnsNullWhenNotFound(): void
    {
        $resolver = $this->makeResolver(['glpi_itilcategories' => []]);

        $this->assertNull($resolver->resolveCategory('Categoria Inexistente'));
    }

    // ------------------------------------------------------------------ //
    // resolveGroup
    // ------------------------------------------------------------------ //

    public function testResolveGroupExactMatch(): void
    {
        $resolver = $this->makeResolver([
            'glpi_groups' => [
                ['id' => 28, 'name' => 'Centro de Servicios', 'is_deleted' => 0],
            ],
        ]);

        $this->assertSame(28, $resolver->resolveGroup('Centro de Servicios'));
    }

    public function testResolveGroupReturnsNullWhenNotFound(): void
    {
        $resolver = $this->makeResolver(['glpi_groups' => []]);

        $this->assertNull($resolver->resolveGroup('Grupo Inexistente'));
    }

    // ------------------------------------------------------------------ //
    // resolveUserByEmail
    // ------------------------------------------------------------------ //

    public function testResolveUserByEmailExactMatch(): void
    {
        $resolver = $this->makeResolver([
            'glpi_useremails_joined' => [
                ['id' => 42, 'email' => 'tech@daycohost.com'],
            ],
        ]);

        $this->assertSame(42, $resolver->resolveUserByEmail('tech@daycohost.com'));
    }

    public function testResolveUserByEmailCaseInsensitive(): void
    {
        $resolver = $this->makeResolver([
            'glpi_useremails_joined' => [
                ['id' => 42, 'email' => 'Tech@Daycohost.com'],
            ],
        ]);

        $this->assertSame(42, $resolver->resolveUserByEmail('tech@daycohost.com'));
    }

    public function testResolveUserByEmailReturnsNullForEmpty(): void
    {
        $resolver = $this->makeResolver(['glpi_useremails_joined' => []]);

        $this->assertNull($resolver->resolveUserByEmail(''));
    }

    public function testResolveUserByEmailReturnsNullWhenNotFound(): void
    {
        $resolver = $this->makeResolver(['glpi_useremails_joined' => []]);

        $this->assertNull($resolver->resolveUserByEmail('nobody@example.com'));
    }

    // ------------------------------------------------------------------ //
    // normalize (public for testability)
    // ------------------------------------------------------------------ //

    public function testNormalizeLowercasesAndStripsAccents(): void
    {
        $resolver = $this->makeResolver([]);

        $this->assertSame('acumuladores duncan, c.a.', $resolver->normalize('Acumuladores Duncan, C.A.'));
        $this->assertSame('alcaldia del municipio sucre', $resolver->normalize('Alcaldía del Municipio Sucre'));
        $this->assertSame('banesco banco universal, c.a.', $resolver->normalize('Banesco Banco Universal, C.A.'));
    }
}

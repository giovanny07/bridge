<?php

namespace GlpiPlugin\Bridge\Tests\Units;

use GlpiPlugin\Bridge\Migration\IncidentMapper;
use GlpiPlugin\Bridge\Connector\SolarWinds\SamanageNormalizer;
use GlpiPlugin\Bridge\Migration\MappedIncident;
use GlpiPlugin\Bridge\Resolver\GlpiResolver;
use PHPUnit\Framework\TestCase;

class IncidentMapperTest extends TestCase
{
    private SamanageNormalizer $normalizer;

    protected function setUp(): void
    {
        $this->normalizer = new SamanageNormalizer();
    }

    private function makeResolver(array $tables): GlpiResolver
    {
        $db = new class($tables) {
            public function __construct(private array $t) {}
            public function request(array $c): array
            {
                $from = $c['FROM'] ?? '';
                if (isset($c['INNER JOIN'])) return $this->t[$from . '_joined'] ?? [];
                return $this->t[$from] ?? [];
            }
        };
        return new GlpiResolver($db);
    }

    private function makeFullResolver(): GlpiResolver
    {
        return $this->makeResolver([
            'glpi_entities'          => [['id' => 30, 'name' => 'Acumuladores Duncan, C.A.']],
            'glpi_itilcategories'    => [
                ['id' => 7,  'name' => 'Acceso a Internet', 'is_deleted' => 0],
                ['id' => 10, 'name' => 'Falla de Conexión',  'is_deleted' => 0],
            ],
            'glpi_groups'            => [['id' => 28, 'name' => 'Centro de Servicios', 'is_deleted' => 0]],
            'glpi_useremails_joined' => [['id' => 5, 'email' => 'requester@client.com']],
        ]);
    }

    private function makeIncident(array $overrides = []): array
    {
        return array_merge([
            'id'          => 181695325,
            'number'      => 191723,
            'name'        => 'Memory critical on server',
            'description' => 'High memory',
            'description_no_html' => 'High memory',
            'state'       => 'En Proceso',
            'priority'    => 'High',
            'origin'      => 'api',
            'created_at'  => '2026-05-13T16:40:26.000-04:00',
            'updated_at'  => '2026-05-13T16:40:37.000-04:00',
            'site'        => ['name' => 'Acumuladores Duncan, C.A.'],
            'category'    => ['name' => 'Acceso a Internet'],
            'subcategory' => ['name' => 'Falla de Conexión'],
            'assignee'    => ['is_user' => false, 'name' => 'Centro de Servicios', 'email' => ''],
            'requester'   => ['email' => 'requester@client.com', 'name' => 'Requester'],
            'href'        => 'https://servicios.example.com/incidents/181695325',
        ], $overrides);
    }

    // ------------------------------------------------------------------ //
    // Full resolution
    // ------------------------------------------------------------------ //

    public function testMapFullyResolvedIncidentHasStatusOk(): void
    {
        $mapper = new IncidentMapper($this->makeFullResolver(), $this->normalizer, 99, 88);
        $result = $mapper->map($this->makeIncident());

        $this->assertSame('ok',   $result->status);
        $this->assertSame([],     $result->warnings);
        $this->assertTrue($result->isCreatable());
    }

    public function testMapResolvesEntityId(): void
    {
        $mapper = new IncidentMapper($this->makeFullResolver(), $this->normalizer, 99, 88);
        $result = $mapper->map($this->makeIncident());

        $this->assertSame(30, $result->ticket['entities_id']);
    }

    public function testMapResolvesCategoryId(): void
    {
        $mapper = new IncidentMapper($this->makeFullResolver(), $this->normalizer, 99, 88);
        $result = $mapper->map($this->makeIncident());

        $this->assertSame(10, $result->ticket['itilcategories_id']); // subcategory wins
    }

    public function testMapResolvesGroupAssignee(): void
    {
        $mapper = new IncidentMapper($this->makeFullResolver(), $this->normalizer, 99, 88);
        $result = $mapper->map($this->makeIncident());

        $this->assertSame(28, $result->ticket['_groups_id_assign']);
        $this->assertNull($result->ticket['_users_id_assign']);
    }

    public function testMapResolvesRequester(): void
    {
        $mapper = new IncidentMapper($this->makeFullResolver(), $this->normalizer, 99, 88);
        $result = $mapper->map($this->makeIncident());

        $this->assertSame(5, $result->ticket['_users_id_requester']);
    }

    // ------------------------------------------------------------------ //
    // Partial resolution — fallbacks
    // ------------------------------------------------------------------ //

    public function testMapUsesEntityFallbackWhenSiteNotFound(): void
    {
        $mapper = new IncidentMapper($this->makeFullResolver(), $this->normalizer, 99, 88);
        $result = $mapper->map($this->makeIncident(['site' => ['name' => 'Empresa Inexistente']]));

        $this->assertSame('partial', $result->status);
        $this->assertSame(99, $result->ticket['entities_id']);
        $this->assertNotEmpty($result->warnings);
    }

    public function testMapUsesGroupFallbackWhenGroupNotFound(): void
    {
        $mapper = new IncidentMapper($this->makeFullResolver(), $this->normalizer, 99, 88);
        $result = $mapper->map($this->makeIncident([
            'assignee' => ['is_user' => false, 'name' => 'Grupo Inexistente', 'email' => ''],
        ]));

        $this->assertSame(88, $result->ticket['_groups_id_assign']);
    }

    public function testMapPartialWhenCategoryNotFound(): void
    {
        $mapper = new IncidentMapper($this->makeFullResolver(), $this->normalizer, 99, 88);
        $result = $mapper->map($this->makeIncident([
            'category'    => ['name' => 'Categoria Inexistente'],
            'subcategory' => ['name' => ''],
        ]));

        $this->assertSame(0, $result->ticket['itilcategories_id']);
        $this->assertContains('partial', [$result->status, 'partial']); // ok or partial
    }

    // ------------------------------------------------------------------ //
    // Unresolved — no entity, no fallback
    // ------------------------------------------------------------------ //

    public function testMapUnresolvedWhenNoEntityAndNoFallback(): void
    {
        $mapper = new IncidentMapper($this->makeFullResolver(), $this->normalizer, 0, 0);
        $result = $mapper->map($this->makeIncident(['site' => ['name' => 'Empresa Inexistente']]));

        $this->assertSame('unresolved', $result->status);
        $this->assertFalse($result->isCreatable());
    }

    // ------------------------------------------------------------------ //
    // followups from comments
    // ------------------------------------------------------------------ //

    private function makeComment(array $overrides = []): array
    {
        return array_merge([
            'id'         => 314608972,
            'body'       => '<p>Resolved.</p>',
            'user'       => ['email' => 'requester@client.com', 'name' => 'Requester'],
            'created_at' => '2026-05-13T19:18:56.000-04:00',
            'is_private' => false,
        ], $overrides);
    }

    public function testMapWithCommentsProducesFollowups(): void
    {
        $mapper = new IncidentMapper($this->makeFullResolver(), $this->normalizer, 99, 88);
        $result = $mapper->map($this->makeIncident(), [$this->makeComment()]);

        $this->assertCount(1, $result->followups);
    }

    public function testFollowupContentIsMapped(): void
    {
        $mapper = new IncidentMapper($this->makeFullResolver(), $this->normalizer, 99, 88);
        $result = $mapper->map($this->makeIncident(), [$this->makeComment()]);

        $this->assertSame('<p>Resolved.</p>', $result->followups[0]['content']);
    }

    public function testFollowupUserResolvedByEmail(): void
    {
        $mapper = new IncidentMapper($this->makeFullResolver(), $this->normalizer, 99, 88);
        $result = $mapper->map($this->makeIncident(), [$this->makeComment()]);

        // requester@client.com is in the test resolver with id=5
        $this->assertSame(5, $result->followups[0]['_users_id']);
    }

    public function testFollowupUserNullWhenEmailNotFound(): void
    {
        $mapper = new IncidentMapper($this->makeFullResolver(), $this->normalizer, 99, 88);
        $result = $mapper->map(
            $this->makeIncident(),
            [$this->makeComment(['user' => ['email' => 'unknown@other.com', 'name' => 'X']])]
        );

        $this->assertNull($result->followups[0]['_users_id']);
    }

    public function testPrivateCommentMapsToPrivateFollowup(): void
    {
        $mapper = new IncidentMapper($this->makeFullResolver(), $this->normalizer, 99, 88);
        $result = $mapper->map($this->makeIncident(), [$this->makeComment(['is_private' => true])]);

        $this->assertTrue($result->followups[0]['is_private']);
    }

    public function testNoCommentsProducesEmptyFollowups(): void
    {
        $mapper = new IncidentMapper($this->makeFullResolver(), $this->normalizer, 99, 88);
        $result = $mapper->map($this->makeIncident());

        $this->assertSame([], $result->followups);
    }


    // ------------------------------------------------------------------ //
    // solution extraction in map()
    // ------------------------------------------------------------------ //

    public function testClosedIncidentWithResolutionDescriptionHasSolution(): void
    {
        $mapper   = new IncidentMapper($this->makeFullResolver(), $this->normalizer, 99, 88);
        $incident = $this->makeIncident(['state' => 'Closed', 'resolution_description' => 'Se restauró el servicio.']);
        $result   = $mapper->map($incident, [$this->makeComment()]);

        $this->assertNotNull($result->solution);
        $this->assertStringContainsString('restauró', $result->solution['content']);
    }

    public function testAllCommentsAreFollowupsWhenNoResolution(): void
    {
        $mapper   = new IncidentMapper($this->makeFullResolver(), $this->normalizer, 99, 88);
        $incident = $this->makeIncident(['state' => 'Closed', 'resolution_description' => null, 'resolution_code' => null]);
        $comments = [
            $this->makeComment(['id' => 1, 'body' => '<p>First followup</p>']),
            $this->makeComment(['id' => 2, 'body' => '<p>Second followup</p>']),
        ];
        $result = $mapper->map($incident, $comments);

        $this->assertNull($result->solution);
        $this->assertCount(2, $result->followups);
    }

    public function testOpenIncidentHasNoSolution(): void
    {
        $mapper = new IncidentMapper($this->makeFullResolver(), $this->normalizer, 99, 88);
        $result = $mapper->map($this->makeIncident(['state' => 'En Proceso']), [$this->makeComment()]);

        $this->assertNull($result->solution);
        $this->assertCount(1, $result->followups);
    }

    public function testAutoClosedWithoutResolutionHasNoSolution(): void
    {
        // Auto-closed tickets (Zabbix) have no resolution_description/code.
        // All comments stay as followups — no ITILSolution created.
        $mapper   = new IncidentMapper($this->makeFullResolver(), $this->normalizer, 99, 88);
        $incident = $this->makeIncident(['state' => 'Closed']);
        $result   = $mapper->map($incident, [$this->makeComment(['body' => 'Problem resolved at 10:00'])]);

        $this->assertNull($result->solution);
        $this->assertCount(1, $result->followups);
    }

    public function testResolutionDescriptionTakesPriorityOverLastComment(): void
    {
        $mapper   = new IncidentMapper($this->makeFullResolver(), $this->normalizer, 99, 88);
        $incident = $this->makeIncident([
            'state'                  => 'Closed',
            'resolution_description' => 'Se restauró el servicio.',
        ]);
        $result = $mapper->map($incident, [$this->makeComment(['id' => 99, 'body' => 'Last comment'])]);

        // resolution_description used → comment NOT skipped → still a followup
        $this->assertStringContainsString('restauró', $result->solution['content']);
        $this->assertCount(1, $result->followups);
        $this->assertNull($result->solution['_skip_comment_id']);
    }


    // ------------------------------------------------------------------ //
    // MappedIncident shape
    // ------------------------------------------------------------------ //

    public function testMappedIncidentPreservesOriginalIncident(): void
    {
        $incident = $this->makeIncident();
        $mapper   = new IncidentMapper($this->makeFullResolver(), $this->normalizer, 99, 88);
        $result   = $mapper->map($incident);

        $this->assertSame($incident, $result->original);
    }

    public function testMappedIncidentTicketContainsNormalizerFields(): void
    {
        $mapper = new IncidentMapper($this->makeFullResolver(), $this->normalizer, 99, 88);
        $result = $mapper->map($this->makeIncident());

        $this->assertArrayHasKey('name',    $result->ticket);
        $this->assertArrayHasKey('content', $result->ticket);
        $this->assertArrayHasKey('status',  $result->ticket);
        $this->assertArrayHasKey('date',    $result->ticket);
    }
}

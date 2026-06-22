<?php

namespace GlpiPlugin\Bridge\Tests\Units;

use GlpiPlugin\Bridge\Connector\SolarWinds\SamanageNormalizer;
use PHPUnit\Framework\TestCase;

class SamanageNormalizerTest extends TestCase
{
    private SamanageNormalizer $normalizer;

    protected function setUp(): void
    {
        $this->normalizer = new SamanageNormalizer();
    }

    // ------------------------------------------------------------------ //
    // mapState
    // ------------------------------------------------------------------ //

    #[\PHPUnit\Framework\Attributes\DataProvider('knownStateProvider')]
    public function testMapStateKnownValues(string $samanage, int $expectedGlpi): void
    {
        $this->assertSame($expectedGlpi, $this->normalizer->mapState($samanage));
    }

    public static function knownStateProvider(): array
    {
        return [
            // Spanish states observed on servicios.daycohost.com (2026-05-13)
            ['Pending Assignment',       SamanageNormalizer::GLPI_STATUS_NEW],
            ['En Proceso',               SamanageNormalizer::GLPI_STATUS_ASSIGNED],
            ['Pendiente Acción Cliente', SamanageNormalizer::GLPI_STATUS_PENDING],
            ['Solucionado',              SamanageNormalizer::GLPI_STATUS_SOLVED],
            ['Closed',                   SamanageNormalizer::GLPI_STATUS_CLOSED],
            // English equivalents for other Samanage instances
            ['New',                      SamanageNormalizer::GLPI_STATUS_NEW],
            ['Assigned',                 SamanageNormalizer::GLPI_STATUS_ASSIGNED],
            ['Waiting for Customer',     SamanageNormalizer::GLPI_STATUS_PENDING],
            ['Resolved',                 SamanageNormalizer::GLPI_STATUS_SOLVED],
        ];
    }

    public function testMapStateUnknownValueFallsBackToNew(): void
    {
        $this->assertSame(SamanageNormalizer::GLPI_STATUS_NEW, $this->normalizer->mapState('Whatever'));
    }

    public function testMapStateEmptyStringFallsBackToNew(): void
    {
        $this->assertSame(SamanageNormalizer::GLPI_STATUS_NEW, $this->normalizer->mapState(''));
    }

    // ------------------------------------------------------------------ //
    // mapPriority
    // ------------------------------------------------------------------ //

    #[\PHPUnit\Framework\Attributes\DataProvider('knownPriorityProvider')]
    public function testMapPriorityKnownValues(string $samanage, int $expectedGlpi): void
    {
        $this->assertSame($expectedGlpi, $this->normalizer->mapPriority($samanage));
    }

    public static function knownPriorityProvider(): array
    {
        return [
            // Observed on servicios.daycohost.com (2026-05-13)
            ['Low',      SamanageNormalizer::GLPI_PRIORITY_LOW],
            ['Medium',   SamanageNormalizer::GLPI_PRIORITY_MEDIUM],
            ['High',     SamanageNormalizer::GLPI_PRIORITY_HIGH],
            ['Critical', SamanageNormalizer::GLPI_PRIORITY_VERY_HIGH],
        ];
    }

    public function testMapPriorityUnknownFallsBackToMedium(): void
    {
        $this->assertSame(SamanageNormalizer::GLPI_PRIORITY_MEDIUM, $this->normalizer->mapPriority('Urgent'));
    }

    // ------------------------------------------------------------------ //
    // mapOrigin
    // ------------------------------------------------------------------ //

    #[\PHPUnit\Framework\Attributes\DataProvider('knownOriginProvider')]
    public function testMapOriginKnownValues(string $samanage, int $expectedGlpi): void
    {
        $this->assertSame($expectedGlpi, $this->normalizer->mapOrigin($samanage));
    }

    public static function knownOriginProvider(): array
    {
        return [
            // Observed on servicios.daycohost.com (2026-05-13)
            ['web',      SamanageNormalizer::GLPI_ORIGIN_HELPDESK],
            ['api',      SamanageNormalizer::GLPI_ORIGIN_OTHER],
            ['external', SamanageNormalizer::GLPI_ORIGIN_OTHER],
            ['email',    SamanageNormalizer::GLPI_ORIGIN_EMAIL],
            // Case-insensitive
            ['Web',      SamanageNormalizer::GLPI_ORIGIN_HELPDESK],
            ['API',      SamanageNormalizer::GLPI_ORIGIN_OTHER],
        ];
    }

    public function testMapOriginUnknownFallsBackToOther(): void
    {
        $this->assertSame(SamanageNormalizer::GLPI_ORIGIN_OTHER, $this->normalizer->mapOrigin('portal'));
    }

    // ------------------------------------------------------------------ //
    // parseDate
    // ------------------------------------------------------------------ //

    public function testParseDateConvertsToServerLocalTime(): void
    {
        // Result depends on server timezone. In UTC (test env): 2026-05-13 20:40:26.
        // On Venezuela server (-04:00): 2026-05-13 16:40:26 (local time).
        $result = $this->normalizer->parseDate('2026-05-13T16:40:26.000-04:00');
        $expected = (new \DateTimeImmutable('2026-05-13T16:40:26.000-04:00'))
            ->setTimezone(new \DateTimeZone(date_default_timezone_get()))
            ->format('Y-m-d H:i:s');
        $this->assertSame($expected, $result);
    }

    public function testParseDateReturnsNullForNull(): void
    {
        $this->assertNull($this->normalizer->parseDate(null));
    }

    public function testParseDateReturnsNullForEmptyString(): void
    {
        $this->assertNull($this->normalizer->parseDate(''));
    }

    public function testParseDateReturnsNullForGarbage(): void
    {
        $this->assertNull($this->normalizer->parseDate('not-a-date'));
    }

    public function testParseDateHandlesUtcOffset(): void
    {
        $result = $this->normalizer->parseDate('2026-01-15T10:00:00.000+00:00');
        $this->assertSame('2026-01-15 10:00:00', $result);
    }

    // ------------------------------------------------------------------ //
    // incidentToTicket
    // ------------------------------------------------------------------ //

    private function makeIncident(array $overrides = []): array
    {
        return array_merge([
            'id'                  => 181695325,
            'number'              => 191723,
            'name'                => 'Memory critical on VDCPMWEM2',
            'description'         => '<p>High memory usage detected.</p>',
            'description_no_html' => 'High memory usage detected.',
            'state'               => 'En Proceso',
            'priority'            => 'High',
            'origin'              => 'api',
            'created_at'          => '2026-05-13T16:40:26.000-04:00',
            'updated_at'          => '2026-05-13T16:40:37.000-04:00',
            'href'                => 'https://servicios.daycohost.com/incidents/181695325',
        ], $overrides);
    }

    // ------------------------------------------------------------------ //
    // extractSolution
    // ------------------------------------------------------------------ //

    public function testExtractSolutionReturnsNullForOpenIncident(): void
    {
        $result = $this->normalizer->extractSolution(
            $this->makeIncident(['state' => 'En Proceso']),
            []
        );
        $this->assertNull($result);
    }

    public function testExtractSolutionUsesResolutionDescriptionWhenSet(): void
    {
        $incident = $this->makeIncident([
            'state'                  => 'Closed',
            'resolution_description' => 'Se restauró el servicio correctamente.',
        ]);
        $result = $this->normalizer->extractSolution($incident, []);

        $this->assertNotNull($result);
        $this->assertStringContainsString('restauró', $result['content']);
        $this->assertNull($result['_skip_comment_id']);
    }

    public function testExtractSolutionUsesStateWhenNoResolutionAndNoCode(): void
    {
        // Auto-closed alerts have no resolution_description or resolution_code.
        // A minimal solution using the state name keeps the GLPI timeline consistent.
        $incident = $this->makeIncident(['state' => 'Closed', 'resolution_description' => null, 'resolution_code' => null]);
        $comments = [
            ['id' => 1, 'body' => 'Problem resolved at 10:00', 'user' => ['email' => 'a@b.com', 'name' => 'A'], 'created_at' => '2026-05-13T10:00:00.000-04:00', 'is_private' => false, 'attachments' => []],
        ];

        $result = $this->normalizer->extractSolution($incident, $comments);

        $this->assertNotNull($result);
        $this->assertSame('Closed', $result['content']);
        $this->assertNull($result['_skip_comment_id']);
    }

    public function testExtractSolutionUsesResolutionCode(): void
    {
        $incident = $this->makeIncident(['state' => 'Closed', 'resolution_description' => null, 'resolution_code' => 'Requerimiento Completado']);

        $result = $this->normalizer->extractSolution($incident, []);

        $this->assertNotNull($result);
        $this->assertSame('Requerimiento Completado', $result['content']);
        $this->assertNull($result['_skip_comment_id']);
    }


    public function testIncidentToTicketMapsName(): void
    {
        $ticket = $this->normalizer->incidentToTicket($this->makeIncident());
        // Title is prefixed with the SD number for traceability
        $this->assertSame('[ SD #191723 ] Memory critical on VDCPMWEM2', $ticket['name']);
    }

    public function testIncidentToTicketNameHasNoNumberPrefixWhenNumberMissing(): void
    {
        $ticket = $this->normalizer->incidentToTicket($this->makeIncident(['number' => null]));
        $this->assertSame('Memory critical on VDCPMWEM2', $ticket['name']);
    }

    public function testIncidentToTicketTypeIsIncidentByDefault(): void
    {
        $ticket = $this->normalizer->incidentToTicket($this->makeIncident());
        $this->assertSame(1, $ticket['type']); // Incident
    }

    public function testIncidentToTicketTypeIsServiceRequestWhenFlagTrue(): void
    {
        $ticket = $this->normalizer->incidentToTicket($this->makeIncident(['is_service_request' => true]));
        $this->assertSame(2, $ticket['type']); // Service Request
    }

    public function testIncidentToTicketPrefersDescriptionNoHtml(): void
    {
        $ticket = $this->normalizer->incidentToTicket($this->makeIncident());
        $this->assertSame('High memory usage detected.', $ticket['content']);
        $this->assertStringNotContainsString('<p>', $ticket['content']);
    }

    public function testIncidentToTicketFallsBackToDescriptionWhenNoHtmlMissing(): void
    {
        $incident = $this->makeIncident(['description_no_html' => null]);
        $ticket   = $this->normalizer->incidentToTicket($incident);
        $this->assertSame('<p>High memory usage detected.</p>', $ticket['content']);
    }

    public function testIncidentToTicketMapsStatus(): void
    {
        $ticket = $this->normalizer->incidentToTicket($this->makeIncident(['state' => 'En Proceso']));
        $this->assertSame(SamanageNormalizer::GLPI_STATUS_ASSIGNED, $ticket['status']);
    }

    public function testIncidentToTicketMapsPriority(): void
    {
        $ticket = $this->normalizer->incidentToTicket($this->makeIncident(['priority' => 'High']));
        $this->assertSame(SamanageNormalizer::GLPI_PRIORITY_HIGH, $ticket['priority']);
    }

    public function testIncidentToTicketMapsDate(): void
    {
        $ticket = $this->normalizer->incidentToTicket($this->makeIncident());
        $this->assertSame('2026-05-13 20:40:26', $ticket['date']);
    }

    public function testIncidentToTicketSolveDateSetWhenSolucionado(): void
    {
        $ticket = $this->normalizer->incidentToTicket($this->makeIncident(['state' => 'Solucionado']));
        $this->assertNotNull($ticket['solvedate']);
    }

    public function testIncidentToTicketSolveDateNullWhenOpen(): void
    {
        $ticket = $this->normalizer->incidentToTicket($this->makeIncident(['state' => 'En Proceso']));
        $this->assertNull($ticket['solvedate']);
    }

    public function testIncidentToTicketCloseDateSetWhenClosed(): void
    {
        $ticket = $this->normalizer->incidentToTicket($this->makeIncident(['state' => 'Closed']));
        $this->assertNotNull($ticket['closedate']);
    }

    public function testIncidentToTicketCloseDateNullWhenNotClosed(): void
    {
        $ticket = $this->normalizer->incidentToTicket($this->makeIncident(['state' => 'En Proceso']));
        $this->assertNull($ticket['closedate']);
    }

    public function testIncidentToTicketPreservesSamanageId(): void
    {
        $ticket = $this->normalizer->incidentToTicket($this->makeIncident());
        $this->assertSame(181695325, $ticket['_source_id']);
        $this->assertSame(191723, $ticket['_source_number']);
    }

    public function testIncidentToTicketPreservesHref(): void
    {
        $ticket = $this->normalizer->incidentToTicket($this->makeIncident());
        $this->assertStringContainsString('181695325', $ticket['_source_href']);
    }

    public function testIncidentToTicketIncludesRawPayload(): void
    {
        $incident = $this->makeIncident();
        $ticket   = $this->normalizer->incidentToTicket($incident);
        $this->assertSame($incident, $ticket['_source_raw']);
    }

    // ------------------------------------------------------------------ //
    // commentToFollowup
    // ------------------------------------------------------------------ //

    private function makeComment(array $overrides = []): array
    {
        return array_merge([
            'id'         => 314608972,
            'body'       => '<p>Problem resolved in 12m.</p>',
            'user'       => ['email' => 'tech@daycohost.com', 'name' => 'Tech Support'],
            'created_at' => '2026-05-13T19:18:56.000-04:00',
            'is_private' => false,
        ], $overrides);
    }

    public function testCommentToFollowupMapsBody(): void
    {
        $f = $this->normalizer->commentToFollowup($this->makeComment());
        $this->assertSame('<p>Problem resolved in 12m.</p>', $f['content']);
    }

    public function testCommentToFollowupStripsLinksKeepingText(): void
    {
        // Links to SD tickets must become plain text — they'd be dead links in GLPI
        $body = '<p>Incidente de <a href="https://servicios.daycohost.com/incidents/135126995">#10640 "Node is down"</a> fue cerrada y fusionada aquí.</p>';
        $f    = $this->normalizer->commentToFollowup($this->makeComment(['body' => $body]));
        $this->assertStringNotContainsString('<a ', $f['content']);
        $this->assertStringContainsString('#10640 "Node is down"', $f['content']);
    }

    public function testCommentToFollowupMapsDate(): void
    {
        $f = $this->normalizer->commentToFollowup($this->makeComment());
        $this->assertSame('2026-05-13 23:18:56', $f['date']);
    }

    public function testCommentToFollowupMapsIsPrivate(): void
    {
        $pub  = $this->normalizer->commentToFollowup($this->makeComment(['is_private' => false]));
        $priv = $this->normalizer->commentToFollowup($this->makeComment(['is_private' => true]));
        $this->assertFalse($pub['is_private']);
        $this->assertTrue($priv['is_private']);
    }

    public function testCommentToFollowupPreservesAuthorEmail(): void
    {
        $f = $this->normalizer->commentToFollowup($this->makeComment());
        $this->assertSame('tech@daycohost.com', $f['_author_email']);
    }

    public function testCommentToFollowupUsersIdIsNullInitially(): void
    {
        $f = $this->normalizer->commentToFollowup($this->makeComment());
        $this->assertNull($f['_users_id']);
    }

    public function testCommentToFollowupHandlesMissingFields(): void
    {
        $f = $this->normalizer->commentToFollowup([]);
        $this->assertSame('', $f['content']);
        $this->assertNull($f['date']);
        $this->assertFalse($f['is_private']);
    }

    public function testIncidentToTicketHandlesMissingFieldsGracefully(): void
    {
        $ticket = $this->normalizer->incidentToTicket([]);
        $this->assertSame('', $ticket['name']);
        $this->assertSame('', $ticket['content']);
        $this->assertSame(SamanageNormalizer::GLPI_STATUS_NEW, $ticket['status']);
        $this->assertSame(SamanageNormalizer::GLPI_PRIORITY_MEDIUM, $ticket['priority']);
        $this->assertNull($ticket['date']);
    }

    // ------------------------------------------------------------------ //
    // problemToITIL
    // ------------------------------------------------------------------ //

    public function testProblemToITILMapsTitle(): void
    {
        $result = $this->normalizer->problemToITIL([
            'id' => 1022108, 'number' => 92, 'name' => 'Node down',
            'state' => 'Closed', 'priority' => 'High',
            'created_at' => '2026-05-15T09:42:11.000-04:00',
            'updated_at' => '2026-05-18T09:57:57.000-04:00',
        ]);
        $this->assertSame('[ SD #92 ] Node down', $result['name']);
    }

    public function testProblemToITILMapsProblemSpecificFields(): void
    {
        $result = $this->normalizer->problemToITIL([
            'number'      => 1,
            'name'        => 'Test',
            'state'       => 'Closed',
            'priority'    => 'Medium',
            'root_cause'  => '<p>Root cause text</p>',
            'symptoms'    => '<p>Symptom text</p>',
            'workaround'  => '<p>Workaround text</p>',
            'created_at'  => '2026-05-01T10:00:00.000-04:00',
            'updated_at'  => '2026-05-02T10:00:00.000-04:00',
        ]);
        $this->assertStringContainsString('Root cause text', $result['causecontent']);
        $this->assertStringContainsString('Symptom text',   $result['symptomcontent']);
        $this->assertStringContainsString('Workaround text', $result['_workaround']);
    }

    public function testProblemToITILClosedStateSetsSolvedate(): void
    {
        $result = $this->normalizer->problemToITIL([
            'number' => 1, 'name' => 'T', 'state' => 'Cerrado', 'priority' => 'Low',
            'created_at' => '2026-05-01T10:00:00.000+00:00',
            'updated_at' => '2026-05-02T10:00:00.000+00:00',
        ]);
        $this->assertNotNull($result['solvedate']);
        $this->assertNotNull($result['closedate']);
    }

    // ------------------------------------------------------------------ //
    // changeToITIL
    // ------------------------------------------------------------------ //

    public function testChangeToITILMapsTitle(): void
    {
        $result = $this->normalizer->changeToITIL([
            'id' => 1, 'number' => 4703, 'name' => 'Deploy patch',
            'state' => 'Cerrado', 'priority' => 'High',
            'created_at' => '2026-05-01T10:00:00.000-04:00',
            'updated_at' => '2026-05-02T10:00:00.000-04:00',
        ]);
        $this->assertSame('[ SD #4703 ] Deploy patch', $result['name']);
    }

    public function testChangeToITILMapsChangePlans(): void
    {
        $result = $this->normalizer->changeToITIL([
            'number'        => 1, 'name' => 'T', 'state' => 'Cerrado', 'priority' => 'Low',
            'created_at'    => '2026-05-01T10:00:00.000+00:00',
            'updated_at'    => '2026-05-02T10:00:00.000+00:00',
            'change_plan'   => '<p>Step 1: do X</p>',
            'rollback_plan' => '<p>Rollback: undo X</p>',
            'test_plan'     => '<p>Verify: check X</p>',
        ]);
        $this->assertStringContainsString('Step 1',   $result['rolloutplancontent']);
        $this->assertStringContainsString('Rollback', $result['backoutplancontent']);
        $this->assertStringContainsString('Verify',   $result['checklistcontent']);
    }

    public function testChangeToITILStatusMap(): void
    {
        $cases = [
            'Solicitado'   => 1,
            'Pre Aprobado' => 9,
            'Aprobado'     => 7,
            'Iniciado'     => 2,
            'Revisado'     => 12,
            'Finalizado'   => 6,
            'Cerrado'      => 6,
            'Rechazado'    => 13,
            'Cancelado'    => 14,
            'Expirado'     => 14,
        ];
        foreach ($cases as $state => $expectedStatus) {
            $result = $this->normalizer->changeToITIL([
                'number' => 1, 'name' => 'T', 'state' => $state, 'priority' => 'Low',
                'created_at' => '2026-05-01T10:00:00.000+00:00',
                'updated_at' => '2026-05-02T10:00:00.000+00:00',
            ]);
            $this->assertSame($expectedStatus, $result['status'], "State '$state' should map to $expectedStatus");
        }
    }

    // ------------------------------------------------------------------ //
    // changeTaskToITILTask
    // ------------------------------------------------------------------ //

    public function testChangeTaskToITILTaskMapsContentAndMetadata(): void
    {
        $result = $this->normalizer->changeTaskToITILTask([
            'id'          => 123,
            'name'        => 'Approve deployment',
            'description' => '<p>Review <a href="https://example.test">request</a></p>',
            'task_type'   => 'approval',
            'response'    => 'Approved',
            'created_at'  => '2026-06-01T10:00:00.000-04:00',
            'updated_at'  => '2026-06-01T11:00:00.000-04:00',
            'href'        => 'https://api.samanage.com/tasks/123',
        ]);

        $this->assertStringContainsString('[SolarWinds task #123] Approve deployment', $result['content']);
        $this->assertStringContainsString('Review request', $result['content']);
        $this->assertStringContainsString('Type: approval', $result['content']);
        $this->assertStringContainsString('Response: Approved', $result['content']);
        $this->assertSame(123, $result['_source_id']);
        $this->assertSame('https://api.samanage.com/tasks/123', $result['_source_href']);
    }

    public function testChangeTaskToITILTaskMapsTodoStateWhenOpen(): void
    {
        $result = $this->normalizer->changeTaskToITILTask([
            'id'         => 123,
            'name'       => 'Pending implementation',
            'created_at' => '2026-06-01T10:00:00.000-04:00',
        ]);

        $this->assertSame(SamanageNormalizer::GLPI_TASK_TODO, $result['state']);
    }

    public function testChangeTaskToITILTaskMapsDoneStateWhenCompleted(): void
    {
        $result = $this->normalizer->changeTaskToITILTask([
            'id'           => 123,
            'name'         => 'Implemented',
            'created_at'   => '2026-06-01T10:00:00.000-04:00',
            'completed_at' => '2026-06-01T12:00:00.000-04:00',
        ]);

        $this->assertSame(SamanageNormalizer::GLPI_TASK_DONE, $result['state']);
        $this->assertNotNull($result['_completed_at']);
    }

    public function testChangeTaskToITILTaskAddsPlanWhenDueAtIsAfterCreation(): void
    {
        $result = $this->normalizer->changeTaskToITILTask([
            'id'         => 123,
            'name'       => 'Planned task',
            'created_at' => '2026-06-01T10:00:00.000-04:00',
            'due_at'     => '2026-06-01T12:00:00.000-04:00',
        ]);

        $this->assertArrayHasKey('plan', $result);
        $this->assertSame($result['date'], $result['plan']['begin']);
        $this->assertNotSame($result['plan']['begin'], $result['plan']['end']);
    }

    public function testChangeTaskToITILTaskPreservesAssigneeEmails(): void
    {
        $result = $this->normalizer->changeTaskToITILTask([
            'id' => 123,
            'name' => 'Assigned task',
            'assignee' => ['email' => 'tech@example.com', 'name' => 'Tech User'],
            'requester' => ['email' => 'requester@example.com'],
        ]);

        $this->assertSame('tech@example.com', $result['_assignee_email']);
        $this->assertSame('Tech User', $result['_assignee_name']);
        $this->assertSame('requester@example.com', $result['_requester_email']);
    }
}

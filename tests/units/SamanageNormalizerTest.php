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
        $this->assertSame('Memory critical on VDCPMWEM2', $ticket['name']);
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
}

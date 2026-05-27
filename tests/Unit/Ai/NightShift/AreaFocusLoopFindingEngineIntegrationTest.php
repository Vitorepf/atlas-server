<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\NightShift;

use App\Services\Ai\NightShift\AreaFocusLoopReadModelService;
use App\Services\Ai\NightShift\AtlasNightShiftAreaFocusContractRegistry;
use App\Services\Ai\SelfDirectedEvolution\SelfDirectedEvolutionGapReadModelService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AgenticEngineeringOsFindingEngineService;
use Tests\TestCase;

/**
 * AP-717 — verifies the Area Focus Loop (Slice 1) consumes the Area Finding
 * Engine as an OPTIONAL source: off by default (byte-identical report), opt-in
 * via `include_area_findings` / `area_findings`, with a clean fallback.
 *
 * This test owns only its own file; it does not touch the Slice 1 read model's
 * own test suite.
 */
class AreaFocusLoopFindingEngineIntegrationTest extends TestCase
{
    private function service(): AreaFocusLoopReadModelService
    {
        return app(AreaFocusLoopReadModelService::class);
    }

    /**
     * @return array<string,bool>
     */
    private function ownerDocsPresent(): array
    {
        $docs = (new AtlasNightShiftAreaFocusContractRegistry())
            ->resolve(AtlasNightShiftAreaFocusContractRegistry::AREA_AGENTIC_ENGINEERING_OS)['area_owner_docs'];
        $map = [];
        foreach ($docs as $doc) {
            $map[$doc] = true;
        }

        return $map;
    }

    /**
     * @return array<string,mixed>
     */
    private function emptyGapReport(): array
    {
        return [
            'schema_version' => SelfDirectedEvolutionGapReadModelService::REPORT_SCHEMA,
            'status' => 'ready',
            'candidates' => [],
        ];
    }

    public function test_area_finding_engine_is_off_by_default(): void
    {
        $report = $this->service()->project([
            'owner_doc_status' => $this->ownerDocsPresent(),
            'gap_read_model' => $this->emptyGapReport(),
        ]);

        $this->assertArrayNotHasKey('area_finding_engine', $report);
    }

    public function test_area_finding_engine_attached_via_override(): void
    {
        $engineReport = app(AgenticEngineeringOsFindingEngineService::class)->scan([
            'docs' => [],
            'existing_paths' => [],
            'test_files' => [],
            'service_files' => ['app/Services/Ai/Foo/BarService.php'],
        ]);

        $report = $this->service()->project([
            'owner_doc_status' => $this->ownerDocsPresent(),
            'gap_read_model' => $this->emptyGapReport(),
            'area_findings' => $engineReport,
        ]);

        $this->assertArrayHasKey('area_finding_engine', $report);
        $block = $report['area_finding_engine'];
        $this->assertTrue($block['available']);
        $this->assertSame($engineReport['finding_count'], $block['finding_count']);
        $this->assertSame(
            AgenticEngineeringOsFindingEngineService::REPORT_SCHEMA,
            $block['schema_version'],
        );
        $this->assertTrue($block['self_directed_evolution_remains_gap_owner']);
    }

    public function test_area_finding_engine_resolved_from_container_when_enabled(): void
    {
        $report = $this->service()->project([
            'owner_doc_status' => $this->ownerDocsPresent(),
            'gap_read_model' => $this->emptyGapReport(),
            'include_area_findings' => true,
        ]);

        $this->assertArrayHasKey('area_finding_engine', $report);
        $this->assertTrue($report['area_finding_engine']['available']);
        $this->assertContains($report['area_finding_engine']['engine_status'], ['ready', 'partial', 'blocked']);
    }
}
